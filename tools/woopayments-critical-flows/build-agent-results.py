#!/usr/bin/env python3
"""Build Layer-A critical-flow result JSON from authoritative gate rollups.

The critical-flow runner intentionally does not drive browsers itself. This
adapter preserves that boundary while letting final-evidence gates feed the
runner's `{flow, store_results, parity_verdict, regression_note}` contract.
"""

from __future__ import annotations

import argparse
import binascii
from collections import Counter
import hashlib
import hmac
import importlib.util
import json
import os
import re
import struct
import sys
import zlib
from pathlib import Path, PurePosixPath
from typing import Any
from urllib.parse import unquote

from evidence_context import (
    EvidenceContextError,
    import_captured_result,
    stamp_generated_result,
    validate_context,
)


REPO_ROOT = Path(__file__).resolve().parents[2]
FLOW_MA11 = "MA-11-plugin-active-settings-screen"
FLOW_MD01 = "MD-01-created-note-on-hold-notify"
FLOW_MD02 = "MD-02-save-evidence"
FLOW_MS07 = "MS-07-admin-change-method"
FLOW_SC04 = "SC-04-saved-card"
FLOW_SC14 = "SC-14-lpm-wave-1-checkout"
FLOW_SS10 = "SS-10-sepa-token-renewal-cutover"
SC04_SURFACES = ("classic", "blocks", "sca_classic", "sca_blocks")
PLUGIN_ACTIVE_STORES = {
    "ref": ("reference", "http://localhost:8082"),
    "target": ("target", "http://store8889.localhost:8889"),
}
PLUGIN_ACTIVE_BASE_ARTIFACTS = {
    "plugin-active-settings-blockers.txt",
    "plugin-active-settings-failures.txt",
    "plugin-active-settings.json",
    "plugin-active-settings.png",
}
PLUGIN_ACTIVE_BROWSER_LOG_ARTIFACTS = {"plugin-active-settings.browser.log"}
PLUGIN_ACTIVE_TARGET_ARTIFACTS = {
    "plugin-active-settings-restore.json",
    "plugin-active-settings-snapshot.json",
    "plugin-active-settings-stage.json",
}
MD01_ASSERTIONS = {
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
MD01_BROWSER_FIELDS = {
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
    "raw_sha256",
    "context_hmac",
    "payload_sha256",
}


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_result(
    out_dir: Path,
    flow: str,
    payload: dict[str, Any],
    context: dict[str, Any],
    *,
    archive_relative: bool = False,
) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / f"{flow}.json").write_text(
        json.dumps(
            stamp_generated_result(payload, context, evidence_base_dir=out_dir if archive_relative else None),
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )


def existing_path(path: str | None) -> Path | None:
    if not path:
        return None
    candidate = Path(path)
    return candidate if candidate.exists() else None


def clean_list(values: Any) -> list[str]:
    if not isinstance(values, list):
        return []
    return [str(value) for value in values if str(value)]


def verdict_for_status(status: str, *, pass_verdict: str = "PASS") -> str:
    normalized = status.lower()
    if normalized == "pass":
        return pass_verdict
    if normalized == "fail":
        return "FAIL - functional"
    return "BLOCKED"


def sha256_file(path: Path) -> str:
    return f"sha256:{hashlib.sha256(path.read_bytes()).hexdigest()}"


def validate_png_screenshot(path: Path) -> None:
    data = path.read_bytes()
    if not data.startswith(b"\x89PNG\r\n\x1a\n"):
        raise ValueError("plugin-active screenshot is not a PNG")

    offset = 8
    chunks: list[tuple[bytes, bytes]] = []
    known_critical_chunks = {b"IHDR", b"PLTE", b"IDAT", b"IEND"}
    idat_started = False
    idat_ended = False
    while offset < len(data):
        if offset + 12 > len(data):
            raise ValueError("plugin-active screenshot has a truncated PNG chunk")
        length = struct.unpack(">I", data[offset : offset + 4])[0]
        chunk_type = data[offset + 4 : offset + 8]
        chunk_end = offset + 12 + length
        if chunk_end > len(data):
            raise ValueError("plugin-active screenshot has a truncated PNG payload")
        chunk_data = data[offset + 8 : offset + 8 + length]
        if not all(65 <= byte <= 90 or 97 <= byte <= 122 for byte in chunk_type):
            raise ValueError("plugin-active screenshot has an invalid PNG chunk type")
        if not chunk_type[0] & 0x20 and chunk_type not in known_critical_chunks:
            raise ValueError("plugin-active screenshot has an unknown critical PNG chunk")
        if chunk_type == b"IDAT":
            if not chunk_data or idat_ended:
                raise ValueError("plugin-active screenshot has invalid or noncontiguous PNG image data")
            idat_started = True
        elif idat_started:
            idat_ended = True
        expected_crc = struct.unpack(">I", data[offset + 8 + length : chunk_end])[0]
        actual_crc = binascii.crc32(chunk_type + chunk_data) & 0xFFFFFFFF
        if actual_crc != expected_crc:
            raise ValueError("plugin-active screenshot has an invalid PNG checksum")
        chunks.append((chunk_type, chunk_data))
        offset = chunk_end
        if chunk_type == b"IEND":
            break

    chunk_types = [chunk_type for chunk_type, _chunk_data in chunks]
    if (
        offset != len(data)
        or not chunks
        or chunks[0][0] != b"IHDR"
        or chunks[-1][0] != b"IEND"
        or chunk_types.count(b"IHDR") != 1
        or chunk_types.count(b"IEND") != 1
        or chunk_types.count(b"PLTE") > 1
        or chunks[-1][1]
    ):
        raise ValueError("plugin-active screenshot has an invalid PNG structure")
    if len(chunks[0][1]) != 13 or not any(chunk_type == b"IDAT" for chunk_type, _data in chunks):
        raise ValueError("plugin-active screenshot is missing required PNG data")
    width, height, bit_depth, color_type, compression, filter_method, interlace = struct.unpack(
        ">IIBBBBB", chunks[0][1]
    )
    if width < 800 or height < 450 or width > 5_000 or height > 5_000:
        raise ValueError("plugin-active screenshot dimensions are outside the supported 800x450 to 5000x5000 range")
    if (bit_depth, color_type, compression, filter_method, interlace) != (8, 2, 0, 0, 0):
        raise ValueError("plugin-active screenshot does not use the expected non-interlaced RGB PNG format")
    if b"PLTE" in chunk_types:
        palette_index = chunk_types.index(b"PLTE")
        palette_data = chunks[palette_index][1]
        if (
            palette_index > chunk_types.index(b"IDAT")
            or not palette_data
            or len(palette_data) > 768
            or len(palette_data) % 3
        ):
            raise ValueError("plugin-active screenshot has an invalid PNG palette")
    row_size = 1 + width * 3
    expected_size = row_size * height
    decompressor = zlib.decompressobj()
    try:
        pixels = decompressor.decompress(
            b"".join(chunk_data for chunk_type, chunk_data in chunks if chunk_type == b"IDAT"),
            expected_size + 1,
        )
    except zlib.error as exc:
        raise ValueError("plugin-active screenshot has invalid compressed image data") from exc
    if (
        len(pixels) != expected_size
        or not decompressor.eof
        or decompressor.unconsumed_tail
        or decompressor.unused_data
        or any(pixels[offset] > 4 for offset in range(0, expected_size, row_size))
    ):
        raise ValueError("plugin-active screenshot image data does not match its dimensions")


def validate_plugin_active_responses(evidence: dict[str, Any], target_url: str) -> None:
    response_groups = {
        key: evidence[key]
        for key in ("all_failed_responses", "failed_responses", "blocked_responses")
    }
    for name, responses in response_groups.items():
        for response in responses:
            if not isinstance(response, dict) or set(response) != {"status", "url"}:
                raise ValueError(f"plugin-active browser evidence has malformed {name}")
            status = response.get("status")
            url = response.get("url")
            if (
                not isinstance(status, int)
                or isinstance(status, bool)
                or not 400 <= status <= 599
                or not isinstance(url, str)
                or not url.startswith(target_url + "/")
            ):
                raise ValueError(f"plugin-active browser evidence has invalid {name}")

    def response_key(response: dict[str, Any]) -> str:
        return json.dumps(response, sort_keys=True, separators=(",", ":"))

    if Counter(map(response_key, response_groups["all_failed_responses"])) != Counter(
        map(
            response_key,
            [*response_groups["failed_responses"], *response_groups["blocked_responses"]],
        )
    ):
        raise ValueError("plugin-active browser response partitions are inconsistent")

    def is_optional(response: dict[str, Any]) -> bool:
        decoded_url = str(response["url"])
        for _attempt in range(3):
            decoded = unquote(decoded_url)
            if decoded == decoded_url:
                break
            decoded_url = decoded
        return response["status"] in {401, 403, 500} and bool(
            re.search(r"/wc/v3/payments/deposits/overview-all\b", decoded_url)
        )

    if any(is_optional(response) for response in response_groups["failed_responses"]):
        raise ValueError("plugin-active optional response was misclassified as a product failure")
    if any(not is_optional(response) for response in response_groups["blocked_responses"]):
        raise ValueError("plugin-active hard response failure was misclassified as optional")


def validate_plugin_active_artifacts(store: str, gate_path: Path, payload: dict[str, Any]) -> list[Path]:
    expected_names = set(PLUGIN_ACTIVE_BASE_ARTIFACTS)
    if store == "target":
        expected_names.update(PLUGIN_ACTIVE_TARGET_ARTIFACTS)

    artifacts = payload.get("artifacts")
    if not isinstance(artifacts, list):
        raise ValueError("plugin-active packet artifact manifest is missing")

    base = gate_path.resolve().parent
    artifact_paths: dict[str, Path] = {}
    for artifact in artifacts:
        if not isinstance(artifact, dict):
            raise ValueError("plugin-active packet artifact entry is invalid")
        path = Path(str(artifact.get("path", "")))
        resolved = path.resolve()
        if resolved.parent != base or resolved.name in artifact_paths:
            raise ValueError("plugin-active packet artifact path escaped or was duplicated")
        if not resolved.is_file() or artifact.get("sha256") != sha256_file(resolved):
            raise ValueError(f"plugin-active packet artifact hash mismatch: {resolved.name}")
        artifact_paths[resolved.name] = resolved
    artifact_names = set(artifact_paths)
    if not any(
        artifact_names == expected_names | {log_name}
        for log_name in PLUGIN_ACTIVE_BROWSER_LOG_ARTIFACTS
    ):
        raise ValueError("plugin-active packet artifact set is incomplete or unexpected")

    browser = load_json(artifact_paths["plugin-active-settings.json"])
    if browser != payload.get("evidence"):
        raise ValueError("plugin-active rollup browser evidence differs from the raw artifact")
    screenshot = artifact_paths["plugin-active-settings.png"]
    if Path(str(browser.get("screenshot_path", ""))).resolve() != screenshot:
        raise ValueError("plugin-active browser screenshot path does not match the packet")
    validate_png_screenshot(screenshot)

    if store == "target":
        snapshot = load_json(artifact_paths["plugin-active-settings-snapshot.json"])
        stage = load_json(artifact_paths["plugin-active-settings-stage.json"])
        restore = load_json(artifact_paths["plugin-active-settings-restore.json"])
        snapshot_sha256 = sha256_file(artifact_paths["plugin-active-settings-snapshot.json"])
        if (
            snapshot.get("schema") != "woopayments_plugin_active_fixture_snapshot.v1"
            or snapshot.get("success") is not True
            or snapshot.get("mode") != "snapshot-plugin-active"
            or snapshot.get("errors") != []
            or snapshot.get("was_plugin_active") is not False
            or not isinstance(snapshot.get("candidate_mu_plugins"), list)
            or not snapshot.get("candidate_mu_plugins")
        ):
            raise ValueError("plugin-active target snapshot is invalid")
        seen_candidates: set[str] = set()
        for candidate in snapshot["candidate_mu_plugins"]:
            if not isinstance(candidate, dict):
                raise ValueError("plugin-active target snapshot candidate is invalid")
            source_value = candidate.get("path")
            disabled_value = candidate.get("disabled_path")
            digest = candidate.get("sha256")
            source = PurePosixPath(str(source_value or ""))
            if (
                not source.is_absolute()
                or ".." in source.parts
                or source.suffix != ".php"
                or source.parent.name != "mu-plugins"
                or source.parent.parent.name != "wp-content"
                or str(disabled_value or "") != str(source) + ".disabled-by-woopayments-merge"
                or not isinstance(digest, str)
                or not re.fullmatch(r"[0-9a-f]{64}", digest)
                or str(source) in seen_candidates
            ):
                raise ValueError("plugin-active target snapshot candidate is malformed or duplicated")
            seen_candidates.add(str(source))
        if (
            stage.get("success") is not True
            or stage.get("mode") != "mutate-plugin-active"
            or stage.get("errors") != []
            or stage.get("wcpay_plugin_active") is not True
            or stage.get("runtime_owner") != "plugin"
            or stage.get("snapshot_sha256") != snapshot_sha256
        ):
            raise ValueError("plugin-active target stage is invalid")
        if (
            restore.get("success") is not True
            or restore.get("mode") != "restore-plugin-active"
            or restore.get("errors") != []
            or restore.get("wcpay_plugin_active") is not False
            or restore.get("runtime_owner") != "native"
            or restore.get("snapshot_sha256") != snapshot_sha256
        ):
            raise ValueError("plugin-active target restoration is invalid")

    return [artifact_paths[name] for name in sorted(artifact_paths)]


def validate_plugin_active_packet(
    store: str,
    gate_path: Path,
    payload: dict[str, Any],
    context: dict[str, Any],
) -> list[Path]:
    role, target_url = PLUGIN_ACTIVE_STORES[store]
    settings_url = f"{target_url}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments"
    if payload.get("schema") != "woopayments_plugin_active_settings_gate_rollup.v1":
        raise ValueError("plugin-active gate schema is invalid")
    if payload.get("runner_role") != role:
        raise ValueError(f"plugin-active gate role is not {role}")
    if payload.get("target_url") != target_url or payload.get("settings_url") != settings_url:
        raise ValueError("plugin-active gate URL contract is invalid")
    if payload.get("context_sha256") != context.get("context_sha256"):
        raise ValueError("plugin-active gate context does not match the active aggregate context")
    artifact_paths = validate_plugin_active_artifacts(store, gate_path, payload)
    artifacts_by_name = {path.name: path for path in artifact_paths}
    evidence = payload.get("evidence")
    if not isinstance(evidence, dict):
        raise ValueError("plugin-active browser evidence is invalid")
    expected_common = {
        "schema": "woopayments_plugin_active_settings_browser_evidence.v1",
        "target_url": target_url,
        "settings_url": settings_url,
    }
    for key, value in expected_common.items():
        if evidence.get(key) != value:
            raise ValueError(f"plugin-active browser evidence has invalid {key}")
    for key in (
        "plugin_settings_script_urls",
        "plugin_settings_style_urls",
        "native_settings_asset_urls",
        "duplicate_store_errors",
        "fatal_console_errors",
        "all_failed_responses",
        "failed_responses",
        "blocked_responses",
        "failures",
        "blockers",
        "logs",
    ):
        if not isinstance(evidence.get(key), list):
            raise ValueError(f"plugin-active browser evidence has invalid {key}")
    validate_plugin_active_responses(evidence, target_url)
    derived_plugin_assets_present = bool(evidence["plugin_settings_script_urls"]) and evidence.get(
        "plugin_settings_global_present"
    ) is True
    if evidence.get("plugin_settings_assets_present") is not derived_plugin_assets_present:
        raise ValueError("plugin-active browser asset-presence state is contradictory")
    page = evidence.get("page")
    if not isinstance(page, dict) or page.get("finalUrl") != settings_url:
        raise ValueError("plugin-active browser page did not remain on the canonical settings URL")
    page_authenticated = (
        page.get("hasLoginForm") is False
        and page.get("hasAdminBody") is True
        and page.get("hasAdminMenu") is True
        and page.get("hasWpbodyContent") is True
        and "/wp-admin/" in str(page.get("finalUrl") or "")
    )
    if evidence.get("authenticated_wp_admin") is not page_authenticated:
        raise ValueError("plugin-active browser authentication state contradicts the page")
    common_cross_fields = {
        "settingsScreenPresent": "settings_screen_present",
        "pluginSettingsAssetsPresent": "plugin_settings_assets_present",
        "pluginSettingsGlobalPresent": "plugin_settings_global_present",
        "pluginSettingsScriptUrls": "plugin_settings_script_urls",
        "pluginSettingsStyleUrls": "plugin_settings_style_urls",
        "nativeSettingsAssetUrls": "native_settings_asset_urls",
    }
    for page_key, evidence_key in common_cross_fields.items():
        if page.get(page_key) != evidence.get(evidence_key):
            raise ValueError(f"plugin-active browser page state has inconsistent {page_key}")

    status = payload.get("status")
    if status not in {"pass", "fail", "blocked"} or evidence.get("status") != status:
        raise ValueError("plugin-active gate and browser statuses are invalid or inconsistent")
    failures = payload.get("failures")
    blockers = payload.get("blockers")
    if not isinstance(failures, list) or not isinstance(blockers, list):
        raise ValueError("plugin-active gate verdict details are invalid")
    logs = evidence.get("logs")
    if not isinstance(logs, list):
        raise ValueError("plugin-active browser console log capture is missing")
    log_details = [
        (
            str(log.get("text") or log.get("message") or ""),
            str(log.get("type") or log.get("level") or "").lower(),
        )
        if isinstance(log, dict)
        else (str(log), "")
        for log in logs
    ]
    def is_duplicate_log(log_text: str) -> bool:
        return bool(
            re.search(r"wc/payments/settings", log_text, re.IGNORECASE)
            and re.search(r"already\s+(?:registered|exists)|duplicate", log_text, re.IGNORECASE)
        )

    def is_fatal_log(log_text: str, log_type: str) -> bool:
        if is_duplicate_log(log_text):
            return False
        if re.search(r"uncaught|fatal|exception|typeerror|referenceerror", log_text, re.IGNORECASE):
            return True
        if re.search(r"JQMIGRATE|Permissions policy violation: unload", log_text, re.IGNORECASE):
            return False
        return log_type in {"error", "pageerror"}

    derived_duplicate_logs = [
        log for log, (log_text, _log_type) in zip(logs, log_details) if is_duplicate_log(log_text)
    ]
    derived_fatal_logs = [
        log for log, (log_text, log_type) in zip(logs, log_details) if is_fatal_log(log_text, log_type)
    ]
    if evidence["duplicate_store_errors"] != derived_duplicate_logs:
        raise ValueError("plugin-active duplicate-store errors do not match captured logs")
    if evidence["fatal_console_errors"] != derived_fatal_logs:
        raise ValueError("plugin-active fatal console errors do not match captured logs")
    has_fatal_log = bool(derived_fatal_logs)
    has_duplicate_log = bool(derived_duplicate_logs)
    native_asset_pattern = re.compile(
        re.escape(target_url)
        + r"/wp-content/plugins/woocommerce/assets/client/admin/chunks/"
        + r"settings-payments-woopayments[^?#]*(?:[?#].*)?",
        re.IGNORECASE,
    )
    if not all(
        isinstance(url, str) and native_asset_pattern.fullmatch(url)
        for url in evidence["native_settings_asset_urls"]
    ):
        raise ValueError("plugin-active native settings asset URL is invalid")
    failure_lines = artifacts_by_name["plugin-active-settings-failures.txt"].read_text(encoding="utf-8").splitlines()
    blocker_lines = artifacts_by_name["plugin-active-settings-blockers.txt"].read_text(encoding="utf-8").splitlines()
    if failure_lines != failures or blocker_lines != blockers:
        raise ValueError("plugin-active gate verdict files differ from the rollup")
    if status == "fail":
        evidence_failures = evidence.get("failures")
        if not failures or not isinstance(evidence_failures, list) or not evidence_failures:
            raise ValueError("failed plugin-active evidence has no product failure details")
        if not all(failure in failures for failure in evidence_failures):
            raise ValueError("plugin-active browser failures differ from the gate failures")
        if evidence.get("authenticated_wp_admin") is not True:
            raise ValueError("unauthenticated plugin-active browser evidence is non-gating")
        semantic_failures = []
        if evidence.get("settings_screen_present") is not True:
            semantic_failures.append("WooPayments settings screen is not present")
        if evidence.get("plugin_settings_assets_present") is not True or evidence.get(
            "plugin_settings_global_present"
        ) is not True:
            semantic_failures.append("standalone WooPayments settings assets were not observed")
        if evidence.get("native_settings_asset_urls"):
            semantic_failures.append("native WooPayments settings assets were observed")
        if evidence.get("duplicate_store_errors") or has_duplicate_log:
            semantic_failures.append("duplicate wc/payments/settings store registration error")
        if evidence.get("fatal_console_errors") or has_fatal_log:
            semantic_failures.append("fatal browser console errors were captured")
        if evidence.get("failed_responses"):
            semantic_failures.append("failed browser responses were captured")
        if not semantic_failures or not any(failure in evidence_failures for failure in semantic_failures):
            raise ValueError("failed plugin-active evidence is not backed by a concrete browser defect")
        return artifact_paths
    if status == "blocked":
        evidence_blockers = evidence.get("blockers")
        if (
            failures
            or not blockers
            or not isinstance(evidence_blockers, list)
            or not evidence_blockers
            or evidence["failed_responses"]
            or not evidence["blocked_responses"]
        ):
            raise ValueError("blocked plugin-active evidence has invalid blocker details")
        if not all(blocker in blockers for blocker in evidence_blockers):
            raise ValueError("plugin-active browser blockers differ from the gate blockers")
        return artifact_paths
    if failures or blockers:
        raise ValueError("passing plugin-active gate has verdict details")

    expected_pass = {
        "plugin_active": True,
        "authenticated_wp_admin": True,
        "settings_screen_present": True,
        "plugin_settings_assets_present": True,
        "plugin_settings_global_present": True,
    }
    for key, value in expected_pass.items():
        if evidence.get(key) != value:
            raise ValueError(f"plugin-active browser evidence has invalid {key}")
    for key in (
        "native_settings_asset_urls",
        "duplicate_store_errors",
        "fatal_console_errors",
        "all_failed_responses",
        "failed_responses",
        "blocked_responses",
        "failures",
        "blockers",
    ):
        if evidence.get(key) != []:
            raise ValueError(f"plugin-active browser evidence has nonempty {key}")
    if has_fatal_log:
        raise ValueError("plugin-active browser console log contains a fatal token")
    if has_duplicate_log:
        raise ValueError("plugin-active browser console log contains a duplicate-store token")

    for key, extension in (
        ("plugin_settings_script_urls", "js"),
        ("plugin_settings_style_urls", "css"),
    ):
        urls = evidence.get(key)
        pattern = re.compile(
            re.escape(target_url)
            + rf"/wp-content/plugins/woocommerce-payments/dist/settings(?:\.min)?\.{extension}(?:[?#].*)?"
        )
        if not urls or not all(isinstance(url, str) and pattern.fullmatch(url) for url in urls):
            raise ValueError(f"plugin-active browser evidence has invalid {key}")

    expected_page = {
        "finalUrl": settings_url,
        "hasAdminBody": True,
        "hasAdminMenu": True,
        "hasLoginForm": False,
        "hasWpbodyContent": True,
        "pluginSettingsAssetsPresent": True,
        "pluginSettingsGlobalPresent": True,
        "settingsScreenPresent": True,
        "settingsStoreSelectable": True,
        "nativeSettingsAssetUrls": [],
    }
    for key, value in expected_page.items():
        if page.get(key) != value:
            raise ValueError(f"plugin-active browser page state has invalid {key}")
    selectors = page.get("selectorMatches")
    if not isinstance(selectors, list) or not any(
        match == {"selector": "#wcpay-account-settings-container", "count": 1} for match in selectors
    ):
        raise ValueError("plugin-active settings container was not uniquely captured")

    return artifact_paths


def plugin_active_store_result(
    store: str,
    gate_path: Path | None,
    context: dict[str, Any],
) -> dict[str, Any]:
    if gate_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No plugin-active settings gate rollup was available for {store}.",
            "ux_observations": ["Plugin-active settings UX evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    try:
        payload = load_json(gate_path)
        artifact_paths = validate_plugin_active_packet(store, gate_path, payload, context)
    except (OSError, UnicodeError, json.JSONDecodeError, ValueError) as exc:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"Plugin-active settings evidence failed validation: {exc}",
            "ux_observations": ["Plugin-active settings evidence is non-gating because its packet is invalid."],
            "visual_diffs": [],
            "evidence_paths": [str(gate_path)] if gate_path.is_file() else [],
        }
    status = str(payload.get("status", "blocked"))
    evidence = payload.get("evidence", {}) if isinstance(payload.get("evidence"), dict) else {}
    page = evidence.get("page", {}) if isinstance(evidence.get("page"), dict) else {}
    blockers = clean_list(payload.get("blockers")) + clean_list(evidence.get("blockers"))
    failures = clean_list(payload.get("failures")) + clean_list(evidence.get("failures"))
    evidence_paths = [str(gate_path), *(str(path) for path in artifact_paths)]

    observations = [
        "WooPayments plugin-active settings screen rendered in authenticated wp-admin."
        if status == "pass"
        else "WooPayments plugin-active settings screen did not produce passing browser evidence.",
        "Duplicate wc/payments/settings store registration errors were absent."
        if not evidence.get("duplicate_store_errors")
        else "Duplicate wc/payments/settings store registration errors were captured.",
        "Fatal console errors and failed browser responses were absent."
        if not evidence.get("fatal_console_errors") and not evidence.get("failed_responses")
        else "Fatal console errors or failed browser responses were captured.",
    ]
    observations.extend(blockers)
    observations.extend(failures)

    return {
        "store": store,
        "verdict": verdict_for_status(status),
        "end_state": (
            f"status={status}; final_url={page.get('finalUrl') or payload.get('settings_url') or '<unknown>'}; "
            f"settings_screen_present={bool(evidence.get('settings_screen_present'))}"
        ),
        "ux_observations": observations,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
    }


def build_plugin_active_result(
    reference_gate: Path | None,
    target_gate: Path | None,
    context: dict[str, Any],
) -> dict[str, Any]:
    ref = plugin_active_store_result("ref", reference_gate, context)
    target = plugin_active_store_result("target", target_gate, context)
    if ref["verdict"].startswith("PASS") and target["verdict"].startswith("PASS"):
        parity = "PASS"
        note = "Reference and target plugin-active WooPayments settings screens rendered without native settings-store collisions."
    elif ref["verdict"].startswith("FAIL") or target["verdict"].startswith("FAIL"):
        parity = "FAIL - UX"
        note = "At least one plugin-active WooPayments settings screen gate failed."
    else:
        parity = "BLOCKED"
        note = "Plugin-active settings evidence is incomplete."

    return {
        "flow": FLOW_MA11,
        "store_results": [ref, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def lpm_result_for_store(store: str, gate_path: Path | None, payload: dict[str, Any] | None) -> dict[str, Any]:
    if gate_path is None or payload is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No LPM checkout gate rollup was available for {store}.",
            "ux_observations": ["LPM checkout evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    role = "reference" if store == "ref" else "target"
    status = str(payload.get("status", "blocked"))
    results = [result for result in payload.get("results", []) if isinstance(result, dict) and result.get("role") == role]
    blockers = [blocker for blocker in clean_list(payload.get("blockers")) if blocker.startswith(f"{role}/")]
    failures = [failure for failure in clean_list(payload.get("failures")) if failure.startswith(f"{role}/")]
    passed_methods = [
        str(result.get("method"))
        for result in results
        if str(result.get("status", "")).lower() == "pass" and result.get("method")
    ]

    if status == "pass":
        verdict = "PASS"
    elif failures:
        verdict = "FAIL - functional"
    else:
        verdict = "BLOCKED"

    observations = []
    if passed_methods:
        observations.append(f"Runnable methods passed: {', '.join(passed_methods)}.")
    observations.extend(blockers)
    observations.extend(failures)

    return {
        "store": store,
        "verdict": verdict,
        "end_state": (
            f"status={status}; passed_methods={len(passed_methods)}; "
            f"blockers={len(blockers)}; failures={len(failures)}"
        ),
        "ux_observations": observations or ["No per-method LPM evidence was recorded."],
        "visual_diffs": [],
        "evidence_paths": [str(gate_path)],
    }


def build_lpm_result(gate_path: Path | None) -> dict[str, Any]:
    payload = load_json(gate_path) if gate_path else None
    ref = lpm_result_for_store("ref", gate_path, payload)
    target = lpm_result_for_store("target", gate_path, payload)
    status = str(payload.get("status", "blocked")) if payload else "blocked"

    if status == "pass":
        parity = "PASS"
        note = "LPM checkout gate recorded passing reference and target evidence for every requested method."
    elif status == "fail":
        parity = "FAIL - functional"
        note = "LPM checkout gate recorded one or more failures."
    else:
        parity = "BLOCKED"
        note = "LPM checkout gate is blocked on fixture/account prerequisites; runnable methods remain represented in evidence."

    return {
        "flow": FLOW_SC14,
        "store_results": [ref, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def token_continuity_evidence_gaps(payload: dict[str, Any]) -> list[str]:
    token_id = payload.get("token_id")
    source_token = payload.get("source_token")
    native_token = payload.get("native_token_loader")
    render = payload.get("render_payment_methods")
    renewal = payload.get("renewal")
    gaps = []

    if (
        payload.get("source_flow") != "provider_setup_intent"
        or not isinstance(source_token, dict)
        or source_token.get("success") is not True
        or source_token.get("source_payment_method_customer_ready") is not True
        or not str(source_token.get("payment_method_id", "")).startswith("pm_")
    ):
        gaps.append("source token evidence is not a customer-ready provider setup-intent result")

    if (
        not isinstance(token_id, int)
        or token_id <= 0
        or not isinstance(native_token, dict)
        or native_token.get("success") is not True
        or native_token.get("token_id") != token_id
        or native_token.get("gateway_id") != "woocommerce_payments_sepa_debit"
        or native_token.get("token_type") != "wcpay_sepa"
        or not str(native_token.get("token_class", "")).endswith("WooPaymentsSepaToken")
    ):
        gaps.append("native token loader evidence is missing or inconsistent")

    render_page = render.get("page", {}) if isinstance(render, dict) else {}
    payment_methods = render_page.get("payment_methods", {}) if isinstance(render_page, dict) else {}
    if (
        not isinstance(render, dict)
        or render.get("status") != "pass"
        or render.get("token_id") != token_id
        or render.get("token_visible") is not True
        or not isinstance(payment_methods, dict)
        or payment_methods.get("token_visible") is not True
    ):
        gaps.append("My Account token rendering evidence is missing or inconsistent")

    if (
        not isinstance(renewal, dict)
        or renewal.get("success") is not True
        or renewal.get("renewal_processing_model") != "asynchronous_processing"
        or renewal.get("success_checks_failed") != []
        or not isinstance(renewal.get("renewal_order_id"), int)
        or renewal.get("renewal_order_id", 0) <= 0
    ):
        gaps.append("SEPA renewal evidence is missing or inconsistent")

    return gaps


def build_token_continuity_result(gate_path: Path | None) -> dict[str, Any]:
    if gate_path is None:
        store_results = [
            {
                "store": "ref",
                "verdict": "BLOCKED",
                "end_state": "No token-continuity gate rollup was available.",
                "ux_observations": ["SEPA token continuity evidence is missing."],
                "visual_diffs": [],
                "evidence_paths": [],
            },
            {
                "store": "target",
                "verdict": "BLOCKED",
                "end_state": "No token-continuity gate rollup was available.",
                "ux_observations": ["SEPA token continuity evidence is missing."],
                "visual_diffs": [],
                "evidence_paths": [],
            },
        ]
        parity = "BLOCKED"
        note = "Token-continuity evidence is missing."
        return {
            "flow": FLOW_SS10,
            "oracle_mode": "target-only",
            "store_results": store_results,
            "parity_verdict": parity,
            "regression_note": note,
        }

    payload = load_json(gate_path)
    status = str(payload.get("status", "blocked"))
    failures = clean_list(payload.get("failures"))
    blockers = clean_list(payload.get("blockers"))
    evidence_gaps = token_continuity_evidence_gaps(payload) if status == "pass" else []
    source_token = payload.get("source_token", {}) if isinstance(payload.get("source_token"), dict) else {}
    native_token = (
        payload.get("native_token_loader", {}) if isinstance(payload.get("native_token_loader"), dict) else {}
    )
    render = payload.get("render_payment_methods", {}) if isinstance(payload.get("render_payment_methods"), dict) else {}
    renewal = payload.get("renewal", {}) if isinstance(payload.get("renewal"), dict) else {}
    render_page = render.get("page", {}) if isinstance(render.get("page"), dict) else {}
    screenshot = render_page.get("screenshot_path")
    evidence_paths = [str(gate_path)]
    if screenshot:
        evidence_paths.append(str(screenshot))

    if status == "pass" and not evidence_gaps:
        verdict = "PASS"
        parity = "BLOCKED"
        note = "Target-only SEPA cutover continuity passed; cross-store parity is not claimed."
    elif status == "pass":
        verdict = "BLOCKED"
        parity = "BLOCKED"
        note = "Token-continuity rollup claimed pass without complete target evidence."
    elif status == "fail":
        verdict = "FAIL - functional"
        parity = "BLOCKED"
        note = "Target token-continuity gate recorded failures."
    else:
        verdict = "BLOCKED"
        parity = "BLOCKED"
        note = "Target token-continuity gate is blocked."

    reference_result = {
        "store": "ref",
        "verdict": "BLOCKED",
        "end_state": "No equivalent reference-store SEPA scheduled-renewal run is available.",
        "ux_observations": [
            "The token-continuity gate runs before and after cutover on the target store only.",
            "WooPayments 10.8 does not provide an equivalent SEPA scheduled-renewal callback for cross-store parity.",
        ],
        "visual_diffs": [],
        "evidence_paths": [],
    }

    target_result = {
        "store": "target",
        "verdict": verdict,
        "end_state": (
            f"status={status}; native_token_loaded={native_token.get('success')}; "
            f"token_visible={render_page.get('payment_methods', {}).get('token_visible')}; "
            f"renewal_order={renewal.get('renewal_order_id')}; "
            f"renewal_status={renewal.get('renewal_order_status')}; "
            f"subscription_status={renewal.get('subscription_status')}"
        ),
        "ux_observations": [
            "The provider source token was customer-ready before target cutover."
            if source_token.get("source_payment_method_customer_ready")
            else "The provider source token was not proven customer-ready before target cutover.",
            "The native WooCommerce token loader resolved the saved SEPA token."
            if native_token.get("success")
            else "The native WooCommerce token loader did not prove the saved SEPA token.",
            "Native My Account payment methods page showed the migrated SEPA token."
            if render_page.get("payment_methods", {}).get("token_visible")
            else "Native My Account payment methods page did not prove token visibility.",
            f"Renewal processing model={renewal.get('renewal_processing_model') or '<unknown>'}.",
            f"Renewal success checks failed={renewal.get('success_checks_failed') or []}.",
        ]
        + evidence_gaps
        + blockers
        + failures,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
    }

    return {
        "flow": FLOW_SS10,
        "oracle_mode": "target-only",
        "store_results": [reference_result, target_result],
        "parity_verdict": parity,
        "regression_note": note,
    }


def md01_context_key() -> bytes:
    value = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    if re.fullmatch(r"[0-9a-f]{64}", value) is None:
        raise ValueError("MD-01 runner evidence context key is unavailable")
    return bytes.fromhex(value)


def md01_payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    encoded = json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


def validate_md01_seal(payload: dict[str, Any], domain: bytes) -> None:
    if payload.get("payload_sha256") != md01_payload_digest(payload):
        raise ValueError("MD-01 evidence payload digest does not match")
    material = dict(payload)
    material.pop("payload_sha256", None)
    material.pop("context_hmac", None)
    encoded = json.dumps(material, sort_keys=True, separators=(",", ":")).encode("utf-8")
    expected = "hmac-sha256:" + hmac.new(md01_context_key(), domain + encoded, hashlib.sha256).hexdigest()
    if payload.get("context_hmac") != expected:
        raise ValueError("MD-01 evidence context HMAC does not match")


def validate_md01_bound_file(path: Path, expected_digest: str, label: str) -> Path:
    lexical = path
    if sys.platform == "darwin" and len(path.parts) > 1 and path.parts[1] == "var":
        lexical = Path("/private") / Path(*path.parts[1:])
    if (
        not path.is_absolute()
        or ".." in path.parts
        or any(component.is_symlink() for component in (lexical, *lexical.parents))
        or not path.is_file()
        or sha256_file(path) != expected_digest
    ):
        raise ValueError(f"MD-01 {label} is missing, unsafe, or has a mismatched digest")
    return path


def validate_md01_browser_packet(
    path: Path,
    store: str,
    run_stamp: str,
    manifest_digest: str,
) -> tuple[dict[str, Any], list[str]]:
    payload = load_json(path)
    if set(payload) != MD01_BROWSER_FIELDS or payload.get("schema") != "woopayments_md01_browser.v1":
        raise ValueError(f"MD-01 {store} browser packet has an invalid field set or schema")
    validate_md01_seal(payload, b"woopayments-md01-browser-context-v1\0")
    expected_owner = "plugin" if store == "ref" else "native"
    if (
        payload.get("status") != "pass"
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("runtime_owner") != expected_owner
        or payload.get("deterministic_manifest_sha256") != manifest_digest
    ):
        raise ValueError(f"MD-01 {store} browser packet has an invalid run/store/manifest binding")
    assertions = payload.get("assertions")
    if not isinstance(assertions, dict) or set(assertions) != MD01_ASSERTIONS or not all(
        value is True for value in assertions.values()
    ):
        raise ValueError(f"MD-01 {store} browser packet does not prove every UI assertion")
    for field in ("failed_responses", "console_errors", "page_errors", "errors", "blockers"):
        if payload.get(field) != []:
            raise ValueError(f"MD-01 {store} browser packet has non-clean {field}")
    identity = payload.get("identity")
    if (
        not isinstance(identity, dict)
        or set(identity) != {"order_id", "charge_id", "intent_id", "dispute_id"}
        or not isinstance(identity.get("order_id"), int)
        or isinstance(identity.get("order_id"), bool)
        or identity["order_id"] <= 0
        or re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", str(identity.get("charge_id", ""))) is None
        or re.fullmatch(r"pi_[A-Za-z0-9_]+", str(identity.get("intent_id", ""))) is None
        or re.fullmatch(r"[A-Za-z]{2,4}_[A-Za-z0-9_]+", str(identity.get("dispute_id", ""))) is None
    ):
        raise ValueError(f"MD-01 {store} browser packet has an invalid exact identity")
    screenshots = payload.get("screenshots")
    expected_names = {f"{store}-order.png", f"{store}-disputes.png"}
    if not isinstance(screenshots, dict) or set(screenshots) != expected_names:
        raise ValueError(f"MD-01 {store} browser screenshot binding is incomplete")
    evidence_paths = [str(path)]
    for name, digest in screenshots.items():
        screenshot = validate_md01_bound_file(path.parent / name, str(digest), f"{store} screenshot {name}")
        if not screenshot.read_bytes().startswith(b"\x89PNG\r\n\x1a\n"):
            raise ValueError(f"MD-01 {store} screenshot is not a PNG: {name}")
        evidence_paths.append(str(screenshot))
    return payload, evidence_paths


def build_md01_result(gate_path: Path | None, context: dict[str, Any]) -> dict[str, Any]:
    if gate_path is None:
        return build_missing_agent_result(FLOW_MD01)
    gate = load_json(gate_path)
    required_fields = {
        "schema",
        "status",
        "run_stamp",
        "context_binding",
        "deterministic_manifests",
        "results",
        "failures",
        "blockers",
        "cleanup_failures",
        "context_hmac",
        "payload_sha256",
    }
    if set(gate) != required_fields or gate.get("schema") != "woopayments_md01_browser_gate.v1":
        raise ValueError("MD-01 browser gate has an invalid field set or schema")
    validate_md01_seal(gate, b"woopayments-md01-browser-gate-context-v1\0")
    expected_context = {
        "aggregate_run_id": context.get("aggregate_run_id"),
        "context_sha256": context.get("context_sha256"),
    }
    if gate.get("context_binding") != expected_context:
        raise ValueError("MD-01 browser gate does not match the current aggregate context")
    run_stamp = str(gate.get("run_stamp") or "")
    if re.fullmatch(r"[0-9]{8}T[0-9]{6}Z-[0-9]+", run_stamp) is None:
        raise ValueError("MD-01 browser gate has an invalid run stamp")
    manifests = gate.get("deterministic_manifests")
    if not isinstance(manifests, dict) or set(manifests) != {"ref", "target"}:
        raise ValueError("MD-01 deterministic manifest packet is incomplete")
    manifest_paths: dict[str, Path] = {}
    for store in ("ref", "target"):
        binding = manifests[store]
        if not isinstance(binding, dict) or set(binding) != {"path", "sha256"}:
            raise ValueError(f"MD-01 {store} deterministic manifest binding is invalid")
        manifest_paths[store] = validate_md01_bound_file(
            Path(str(binding["path"])), str(binding["sha256"]), f"{store} deterministic manifest"
        )
    results = gate.get("results")
    if not isinstance(results, list) or len(results) != 2:
        raise ValueError("MD-01 browser gate must contain one result for each store")
    by_store: dict[str, dict[str, Any]] = {}
    for result in results:
        if not isinstance(result, dict) or set(result) != {
            "store",
            "status",
            "browser_path",
            "browser_sha256",
            "log_path",
        }:
            raise ValueError("MD-01 browser gate store result is malformed")
        store = str(result.get("store") or "")
        if store not in {"ref", "target"} or store in by_store:
            raise ValueError("MD-01 browser gate store result role is invalid or duplicated")
        by_store[store] = result

    store_results = []
    for store in ("ref", "target"):
        result = by_store[store]
        evidence_paths = [str(gate_path), str(manifest_paths[store])]
        observations: list[str] = []
        if result["status"] == "pass":
            browser_path = validate_md01_bound_file(
                Path(str(result["browser_path"])), str(result["browser_sha256"]), f"{store} browser packet"
            )
            browser, browser_paths = validate_md01_browser_packet(
                browser_path,
                store,
                run_stamp,
                str(manifests[store]["sha256"]),
            )
            evidence_paths.extend(browser_paths)
            identity = browser["identity"]
            verdict = "PASS"
            end_state = (
                f"order={identity['order_id']}; charge={identity['charge_id']}; "
                f"dispute={identity['dispute_id']}; order_status=on-hold; dispute_status=needs_response"
            )
            observations = [
                "Authenticated order detail rendered the on-hold state and dispute-created note with reason/respond-by context.",
                "Payments → Disputes rendered the exact current dispute with needs-response amount/reason, response action, status count and badge.",
            ]
        elif result["status"] == "fail":
            verdict = "FAIL - functional"
            end_state = "The current MD-01 browser contract failed for this store."
            observations = clean_list(gate.get("failures"))
        elif result["status"] == "blocked":
            verdict = "BLOCKED"
            end_state = "The current MD-01 browser contract was blocked for this store."
            observations = clean_list(gate.get("blockers"))
        else:
            raise ValueError(f"MD-01 {store} browser result has an invalid status")
        store_results.append(
            {
                "store": store,
                "verdict": verdict,
                "end_state": end_state,
                "ux_observations": observations,
                "visual_diffs": [],
                "evidence_paths": list(dict.fromkeys(evidence_paths)),
            }
        )

    derived_status = (
        "fail"
        if gate.get("cleanup_failures") or any(result["verdict"].startswith("FAIL") for result in store_results)
        else "blocked"
        if any(result["verdict"] == "BLOCKED" for result in store_results)
        else "pass"
    )
    if gate.get("status") != derived_status:
        raise ValueError("MD-01 browser gate verdict contradicts its store results")
    parity = "PASS" if derived_status == "pass" else "FAIL - functional" if derived_status == "fail" else "BLOCKED"
    note = (
        "Reference and native target render the same provider-created dispute lifecycle end state."
        if parity == "PASS"
        else "At least one MD-01 browser/store result failed."
        if parity.startswith("FAIL")
        else "MD-01 browser evidence is incomplete or blocked."
    )
    return {
        "flow": FLOW_MD01,
        "oracle_mode": "comparable",
        "store_results": store_results,
        "parity_verdict": parity,
        "regression_note": note,
    }


def load_md02_evidence_module():
    path = Path(__file__).resolve().parent / "flows" / "md02-evidence.py"
    spec = importlib.util.spec_from_file_location("md02_evidence", path)
    if spec is None or spec.loader is None:
        raise ValueError("MD-02 evidence core is unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def validate_md02_manifest_packet(
    manifest_path: Path,
    store: str,
    context: dict[str, Any],
    module: Any,
) -> tuple[dict[str, Any], dict[str, Any], list[str]]:
    manifest = load_json(manifest_path)
    status = str(manifest.get("status", ""))
    exit_code = manifest.get("exit_code")
    run_stamp = str(manifest.get("run_stamp", ""))
    run_scope = str(manifest.get("run_scope", ""))
    if status not in module.STATUS_EXIT or exit_code != module.STATUS_EXIT.get(status):
        raise ValueError(f"MD-02 {store} manifest has an invalid verdict")
    module.validate_manifest(
        manifest_path,
        store=store,
        status=status,
        exit_code=exit_code,
        run_stamp=run_stamp,
        run_scope=run_scope,
    )
    packet_name = f"{store}-md02-store-packet.json"
    binding = manifest.get("files", {}).get(packet_name)
    if not isinstance(binding, dict):
        raise ValueError(f"MD-02 {store} manifest has no store packet")
    packet_path = validate_md01_bound_file(
        manifest_path.parent / packet_name,
        str(binding.get("sha256", "")),
        f"{store} store packet",
    )
    packet = load_json(packet_path)
    module.validate_store_packet(packet, store=store, run_stamp=run_stamp)
    expected_context = {
        "aggregate_run_id": context.get("aggregate_run_id"),
        "context_sha256": context.get("context_sha256"),
    }
    if packet.get("context_binding") != expected_context:
        raise ValueError(f"MD-02 {store} packet does not match the current aggregate context")
    source = packet.get("source_manifest", {})
    raw_source_path = Path(str(source.get("path", "")))
    if raw_source_path.is_absolute() or ".." in raw_source_path.parts or not raw_source_path.parts:
        raise ValueError(f"MD-02 {store} inherited source path is not archive-safe")
    source_candidates = (
        packet_path.parent / raw_source_path,
        REPO_ROOT / raw_source_path,
    )
    source_path_candidate = next((candidate for candidate in source_candidates if candidate.is_file()), None)
    if source_path_candidate is None:
        raise ValueError(f"MD-02 {store} inherited source manifest is unavailable")
    source_path = validate_md01_bound_file(
        Path(os.path.abspath(source_path_candidate)),
        str(source.get("sha256", "")),
        f"{store} inherited MD-01 manifest",
    )
    evidence_paths = [str(manifest_path), str(packet_path), str(source_path)]
    for name, artifact_binding in manifest.get("files", {}).items():
        if name == packet_name:
            continue
        artifact = validate_md01_bound_file(
            manifest_path.parent / name,
            str(artifact_binding.get("sha256", "")),
            f"{store} manifest artifact {name}",
        )
        evidence_paths.append(str(artifact))
    screenshots = packet.get("browser", {}).get("screenshots")
    expected_screenshots = {
        f"{store}-md02-form.png",
        f"{store}-md02-saved.png",
        f"{store}-md02-reloaded.png",
    }
    if not isinstance(screenshots, dict) or (
        not set(screenshots).issubset(expected_screenshots)
        if packet.get("browser_status") in {"blocked", "fail_functional"}
        else set(screenshots) != expected_screenshots
    ):
        raise ValueError(f"MD-02 {store} screenshot binding is incomplete")
    for name, digest in screenshots.items():
        screenshot = validate_md01_bound_file(packet_path.parent / name, str(digest), f"{store} screenshot {name}")
        validate_png_screenshot(screenshot)
        evidence_paths.append(str(screenshot))
    return manifest, packet, list(dict.fromkeys(evidence_paths))


def md02_store_result(store: str, packet: dict[str, Any], evidence_paths: list[str]) -> dict[str, Any]:
    browser_status = packet["browser_status"]
    deterministic_status = packet["deterministic_status"]
    observations: list[str] = []
    ux = packet["browser"].get("ux_assertions", {})
    if browser_status == "fail_ux":
        if ux.get("save_for_later_copy") is not True:
            observations.append("The exact Save for later action copy is missing; the client uses different draft-save copy.")
        if ux.get("customer_name_visible") is not True:
            observations.append("The preserved customer name is not visibly presented in the evidence form.")
    observations.extend(str(item) for item in packet.get("diagnostics", []))
    boundary = packet.get("mutation_boundary")
    blocked_boundary = boundary in {"unchanged_safe_failure", "ambiguous_mutation"}
    if (
        packet.get("status") == "blocked"
        or deterministic_status == "blocked"
        or browser_status == "blocked"
        or packet.get("cleanup_failures")
        or blocked_boundary
    ):
        verdict = "BLOCKED"
    elif deterministic_status != "pass" or boundary == "trusted_mutation_mismatch" or browser_status == "fail_functional":
        verdict = "FAIL - functional"
    elif browser_status == "fail_ux":
        verdict = "FAIL - UX"
    elif browser_status == "pass":
        verdict = "PASS"
    else:
        raise ValueError(f"MD-02 {store} has an unknown browser verdict")
    identity = packet["pre_state"]["identity"]
    exact_end_state = (
        f"Dispute {identity['dispute_id']} for order {identity['order_id']} retains an editable, "
        "unsubmitted evidence draft with its original response deadline."
    )
    if verdict == "PASS":
        end_state = exact_end_state
    elif verdict == "FAIL - UX":
        end_state = exact_end_state + " The browser UX checkpoints differ from the reference contract."
    elif verdict == "FAIL - functional" and deterministic_status == "pass":
        end_state = exact_end_state + " The browser functional contract failed."
    elif verdict == "FAIL - functional" and boundary == "trusted_mutation_mismatch":
        end_state = "The captured save request did not establish the required persisted evidence draft."
    elif verdict == "FAIL - functional":
        end_state = "Authoritative state checks did not prove the required persisted, unsubmitted evidence draft."
    elif boundary == "unchanged_safe_failure":
        end_state = "No mutation was observed, but the required end state could not be established authoritatively."
    else:
        end_state = "The required end state could not be established authoritatively."
    return {
        "store": store,
        "verdict": verdict,
        "end_state": end_state,
        "ux_observations": observations,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
    }


def build_md02_result(
    reference_manifest_path: Path | None,
    target_manifest_path: Path | None,
    context: dict[str, Any],
) -> dict[str, Any]:
    if reference_manifest_path is None or target_manifest_path is None:
        result = build_missing_agent_result(FLOW_MD02)
        result["oracle_mode"] = "comparable"
        return result
    module = load_md02_evidence_module()
    manifests: dict[str, dict[str, Any]] = {}
    packets: dict[str, dict[str, Any]] = {}
    store_results = []
    for store, path in (("ref", reference_manifest_path), ("target", target_manifest_path)):
        manifest, packet, evidence_paths = validate_md02_manifest_packet(path, store, context, module)
        manifests[store] = manifest
        packets[store] = packet
        store_results.append(md02_store_result(store, packet, evidence_paths))
    run_stamp = manifests["ref"]["run_stamp"]
    if manifests["target"]["run_stamp"] != run_stamp:
        raise ValueError("MD-02 manifests belong to different runner invocations")
    comparison_binding = manifests["target"].get("files", {}).get("comparison.json")
    if not isinstance(comparison_binding, dict):
        raise ValueError("MD-02 target manifest has no cross-store comparison")
    comparison_path = validate_md01_bound_file(
        target_manifest_path.parent / "comparison.json",
        str(comparison_binding.get("sha256", "")),
        "target comparison",
    )
    comparison = load_json(comparison_path)
    module.validate_comparison(comparison, run_stamp)
    recomputed = module.compare_store_packets(packets["ref"], packets["target"], run_stamp)
    if any(comparison.get(field) != recomputed.get(field) for field in ("schema", "run_stamp", "status", "assertions")):
        raise ValueError("MD-02 cross-store comparison was not recomputed from the bound packets")
    verdicts = [result["verdict"] for result in store_results]
    if any(verdict == "BLOCKED" for verdict in verdicts):
        parity = "BLOCKED"
    elif any(verdict == "FAIL - functional" for verdict in verdicts):
        parity = "FAIL - functional"
    elif any(verdict == "FAIL - UX" for verdict in verdicts):
        parity = "FAIL - UX"
    else:
        parity = "PASS"
    note = (
        "Reference and native target both persist the same recoverable, unsubmitted dispute evidence draft."
        if parity == "PASS"
        else "The deterministic draft lifecycle matches, but the exact evidence-form UX differs."
        if parity == "FAIL - UX"
        else "At least one MD-02 functional contract failed."
        if parity == "FAIL - functional"
        else "MD-02 evidence is incomplete or blocked."
    )
    return {
        "flow": FLOW_MD02,
        "oracle_mode": "comparable",
        "store_results": store_results,
        "parity_verdict": parity,
        "regression_note": note,
    }


def sc04_store_result(
    store: str,
    browser_path: Path | None,
    state_path: Path | None,
    context: dict[str, Any],
) -> dict[str, Any]:
    if browser_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No SC-04 browser evidence was available for {store}.",
            "ux_observations": ["Saved-card Classic and Blocks browser evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    browser = load_json(browser_path)
    evidence_paths = [str(browser_path)]
    observations: list[str] = []
    binding_gaps: list[str] = []
    evidence_gaps: list[str] = []
    behavior_failures: list[str] = []

    browser_surfaces = browser.get("surfaces") if isinstance(browser.get("surfaces"), dict) else {}
    for surface in SC04_SURFACES:
        browser_surface = browser_surfaces.get(surface)
        if not isinstance(browser_surface, dict):
            evidence_gaps.append(f"{surface} browser evidence is missing")
            continue
        screenshot_paths = browser_surface.get("screenshot_paths")
        if not isinstance(screenshot_paths, list) or not screenshot_paths:
            evidence_gaps.append(f"{surface} browser evidence has no screenshot")
            continue
        for value in screenshot_paths:
            screenshot = Path(str(value))
            if screenshot.is_file():
                evidence_paths.append(str(screenshot))
            else:
                evidence_gaps.append(f"{surface} screenshot is missing: {screenshot}")

    if state_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": "SC-04 saved-card browser evidence exists, but matching order/token state evidence is missing.",
            "ux_observations": evidence_gaps + ["Saved-card order/token state evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    state = load_json(state_path)
    evidence_paths.append(str(state_path))
    state_orders = state.get("orders") if isinstance(state.get("orders"), dict) else {}
    token = state.get("token") if isinstance(state.get("token"), dict) else {}
    sca_token = state.get("sca_token") if isinstance(state.get("sca_token"), dict) else {}
    browser_customer_id = str(browser.get("customer_id") or "")
    state_customer_id = str(state.get("customer_id") or "")
    browser_token_id = str(browser.get("token_id") or "")
    browser_sca_token_id = str(browser.get("sca_token_id") or "")
    state_token_id = str(token.get("id") or "")
    state_sca_token_id = str(sca_token.get("id") or "")
    payment_method_id = str(token.get("payment_method_id") or "")
    sca_payment_method_id = str(sca_token.get("payment_method_id") or "")
    expected_context_binding = {
        "aggregate_run_id": context.get("aggregate_run_id"),
        "context_sha256": context.get("context_sha256"),
    }

    if browser.get("schema") != "woopayments_sc04_browser_evidence.v1":
        binding_gaps.append("browser evidence schema is invalid")
    if state.get("schema") != "woopayments_sc04_state_evidence.v1":
        binding_gaps.append("state evidence schema is invalid")
    if browser.get("context_binding") != expected_context_binding:
        binding_gaps.append("browser context binding does not match")
    if state.get("context_binding") != expected_context_binding:
        binding_gaps.append("state context binding does not match")
    if str(browser.get("store") or "") != store:
        binding_gaps.append(f"browser store does not match {store}")
    if str(state.get("store") or "") != store:
        binding_gaps.append(f"state store does not match {store}")
    if not browser_customer_id or browser_customer_id != state_customer_id:
        binding_gaps.append("browser and state customer fixtures do not match")
    if not browser_token_id or browser_token_id != state_token_id:
        binding_gaps.append("browser and state token fixtures do not match")
    if not browser_sca_token_id or browser_sca_token_id != state_sca_token_id:
        binding_gaps.append("browser and state SCA token fixtures do not match")
    if str(token.get("user_id") or "") != state_customer_id:
        binding_gaps.append("saved token does not belong to the evidence customer")
    if token.get("gateway_id") != "woocommerce_payments":
        binding_gaps.append("saved token is not owned by the WooPayments card gateway")
    if not payment_method_id.startswith("pm_"):
        binding_gaps.append("saved token provider payment method id is invalid")
    if str(sca_token.get("user_id") or "") != state_customer_id:
        binding_gaps.append("saved SCA token does not belong to the evidence customer")
    if sca_token.get("gateway_id") != "woocommerce_payments":
        binding_gaps.append("saved SCA token is not owned by the WooPayments card gateway")
    if not sca_payment_method_id.startswith("pm_"):
        binding_gaps.append("saved SCA token provider payment method id is invalid")
    if str(browser.get("home_url") or "").rstrip("/") != str(state.get("home_url") or "").rstrip("/"):
        binding_gaps.append("browser and state home URLs do not match")
    if state.get("source") != context.get("source"):
        binding_gaps.append("source snapshot does not match")
    if state.get("store_context") != context.get("stores", {}).get(store):
        binding_gaps.append("store identity or account state does not match")

    behavior_failures.extend(clean_list(browser.get("errors")))
    behavior_failures.extend(clean_list(browser.get("fatal_console_errors")))
    behavior_failures.extend(clean_list(browser.get("fatal_response_errors")))
    behavior_failures.extend(clean_list(state.get("errors")))

    order_summaries = []
    surface_outcomes: dict[str, dict[str, str]] = {}
    for surface in SC04_SURFACES:
        browser_surface = browser_surfaces.get(surface)
        order = state_orders.get(surface)
        if not isinstance(browser_surface, dict) or not isinstance(order, dict):
            if not isinstance(order, dict):
                evidence_gaps.append(f"{surface} order state evidence is missing")
            continue

        browser_order_id = str(browser_surface.get("order_id") or "")
        state_order_id = str(order.get("order_id") or "")
        requires_sca = surface.startswith("sca_")
        expected_token_id = state_sca_token_id if requires_sca else state_token_id
        expected_payment_method_id = sca_payment_method_id if requires_sca else payment_method_id
        order_summaries.append(f"{surface}_order={state_order_id or '<missing>'}")
        if not browser_order_id or browser_order_id != state_order_id:
            binding_gaps.append(f"{surface} browser and state order ids do not match")
        if str(browser_surface.get("selected_token_id") or "") != expected_token_id:
            binding_gaps.append(f"{surface} selected token does not match the saved-token fixture")
        if str(order.get("customer_id") or "") != state_customer_id:
            binding_gaps.append(f"{surface} order customer does not match the saved-token fixture")

        for field in (
            "success",
            "saved_card_visible",
            "saved_card_selected",
            "new_card_fields_forced",
            "final_url",
            "expected_amount",
            "expected_currency",
        ):
            if field not in browser_surface:
                evidence_gaps.append(f"{surface}: {field} assertion is missing")
        for field in ("success", "status", "payment_method", "payment_method_id", "amount", "currency"):
            if field not in order:
                evidence_gaps.append(f"{surface}: order {field} assertion is missing")

        surface_errors = clean_list(browser_surface.get("errors")) + clean_list(order.get("errors"))
        behavior_failures.extend(surface_errors)
        if "success" in browser_surface and browser_surface.get("success") is not True:
            behavior_failures.append(f"{surface}: browser checkout did not succeed")
        if "saved_card_visible" in browser_surface and browser_surface.get("saved_card_visible") is not True:
            behavior_failures.append(f"{surface}: saved-card affordance was not visible")
        if "saved_card_selected" in browser_surface and browser_surface.get("saved_card_selected") is not True:
            behavior_failures.append(f"{surface}: saved card was not selected")
        if "new_card_fields_forced" in browser_surface and browser_surface.get("new_card_fields_forced") is not False:
            behavior_failures.append(f"{surface}: empty new-card fields were forced")
        if requires_sca:
            for field in ("sca_challenge_present", "sca_challenge_completed"):
                if field not in browser_surface:
                    evidence_gaps.append(f"{surface}: {field} assertion is missing")
            if "sca_challenge_present" in browser_surface and browser_surface.get("sca_challenge_present") is not True:
                behavior_failures.append(f"{surface}: saved-token SCA challenge did not render")
            if "sca_challenge_completed" in browser_surface and browser_surface.get("sca_challenge_completed") is not True:
                behavior_failures.append(f"{surface}: saved-token SCA challenge did not complete")
        expected_final_prefix = str(browser.get("home_url") or "").rstrip("/")
        final_url = str(browser_surface.get("final_url") or "")
        if "final_url" in browser_surface and (
            not final_url.startswith(expected_final_prefix) or "/order-received/" not in final_url
        ):
            behavior_failures.append(f"{surface}: browser did not finish on the local order-received page")
        if "success" in order and order.get("success") is not True:
            behavior_failures.append(f"{surface}: order state assertion did not succeed")
        if "status" in order and order.get("status") not in {"processing", "completed"}:
            behavior_failures.append(f"{surface}: order status is not processing/completed")
        if "payment_method" in order and order.get("payment_method") != "woocommerce_payments":
            behavior_failures.append(f"{surface}: order payment method is not WooPayments card")
        if "payment_method_id" in order and str(order.get("payment_method_id") or "") != expected_payment_method_id:
            behavior_failures.append(f"{surface}: order did not charge the saved provider payment method")
        expected_amount = str(browser_surface.get("expected_amount") or "")
        expected_currency = str(browser_surface.get("expected_currency") or "").upper()
        actual_amount = str(order.get("amount") or "")
        actual_currency = str(order.get("currency") or "").upper()
        if "expected_amount" in browser_surface and "amount" in order and expected_amount != actual_amount:
            behavior_failures.append(f"{surface}: amount mismatch")
        if "expected_currency" in browser_surface and "currency" in order and expected_currency != actual_currency:
            behavior_failures.append(f"{surface}: currency mismatch")
        surface_outcomes[surface] = {
            "amount": actual_amount,
            "currency": actual_currency,
        }
        meta_presence = order.get("meta_presence") if isinstance(order.get("meta_presence"), dict) else {}
        for meta_key in ("_intent_id", "_charge_id"):
            if meta_key not in meta_presence:
                evidence_gaps.append(f"{surface}: {meta_key} assertion is missing")
            elif meta_presence.get(meta_key) is not True:
                behavior_failures.append(f"{surface}: {meta_key} is missing")

        if not surface_errors and browser_surface.get("success") is True:
            observations.append(
                f"{surface.replace('_', ' ').title()} checkout offered and selected saved token {expected_token_id}, then reached order {state_order_id}."
            )

    end_state = "; ".join(
        [
            f"customer={state_customer_id or '<missing>'}",
            f"token={state_token_id or '<missing>'}",
            f"sca_token={state_sca_token_id or '<missing>'}",
            *order_summaries,
        ]
    )
    if binding_gaps or evidence_gaps:
        verdict = "BLOCKED"
    elif behavior_failures:
        verdict = "FAIL - functional"
    else:
        verdict = "PASS"

    return {
        "store": store,
        "verdict": verdict,
        "end_state": end_state,
        "ux_observations": observations + binding_gaps + evidence_gaps + behavior_failures,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
        "surface_outcomes": surface_outcomes,
    }


def build_sc04_result(
    reference_browser: Path | None,
    reference_state: Path | None,
    target_browser: Path | None,
    target_state: Path | None,
    context: dict[str, Any],
) -> dict[str, Any]:
    reference = sc04_store_result("ref", reference_browser, reference_state, context)
    target = sc04_store_result("target", target_browser, target_state, context)

    if reference["verdict"] == "PASS" and target["verdict"] == "PASS":
        if reference.get("surface_outcomes") == target.get("surface_outcomes"):
            parity = "PASS"
            note = "Reference and target Classic and Blocks checkouts selected and charged the saved card token."
        else:
            parity = "FAIL - functional"
            note = "Reference and target SC-04 amount/currency outcomes differ."
    elif reference["verdict"].startswith("FAIL") or target["verdict"].startswith("FAIL"):
        parity = "FAIL - functional"
        note = "At least one store failed the SC-04 saved-card browser or order-state assertion."
    else:
        parity = "BLOCKED"
        note = "SC-04 saved-card evidence is incomplete or does not match the current source/store context."

    return {
        "flow": FLOW_SC04,
        "store_results": [reference, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def ms07_screenshot_paths(browser_payload: dict[str, Any]) -> list[str]:
    evidence_dir = browser_payload.get("evidence_dir")
    if not evidence_dir:
        return []

    base = Path(str(evidence_dir))
    return [str(path) for path in (base / "before.png", base / "selected.png", base / "after.png") if path.exists()]


def ms07_store_result(
    store: str,
    browser_path: Path | None,
    state_path: Path | None,
    expected_subscription_id: int | None,
) -> dict[str, Any]:
    evidence_paths: list[str] = []
    observations: list[str] = []

    if browser_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No MS-07 browser evidence was available for {store}.",
            "ux_observations": ["Subscription admin change-payment browser evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    browser = load_json(browser_path)
    evidence_paths.append(str(browser_path))
    evidence_paths.extend(ms07_screenshot_paths(browser))

    selected_token_id = str(browser.get("selected_token_id") or "")
    token_after_select = str(browser.get("token_value_after_select") or "")
    token_options = browser.get("token_options_before") if isinstance(browser.get("token_options_before"), list) else []
    method_options = browser.get("method_options") if isinstance(browser.get("method_options"), list) else []
    has_requested_token = any(str(option.get("value", "")) == selected_token_id for option in token_options if isinstance(option, dict))
    has_woopayments_method = any(str(option.get("value", "")) == "woocommerce_payments" for option in method_options if isinstance(option, dict))
    browser_errors = clean_list(browser.get("errors"))

    observations.append(
        "WooPayments was selectable in the subscription payment method dropdown."
        if has_woopayments_method
        else "WooPayments was not proven selectable in the subscription payment method dropdown."
    )
    observations.append(
        f"Saved token {selected_token_id} was available and selected in the admin UI."
        if has_requested_token and token_after_select == selected_token_id
        else f"Saved token selection was not proven; requested={selected_token_id}, selected={token_after_select or '<missing>'}."
    )
    observations.extend(browser_errors)

    if state_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": (
                f"subscription={browser.get('subscription_id')}; selected_token={selected_token_id}; "
                "renewal state evidence is missing"
            ),
            "ux_observations": observations + ["MS-07 renewal/state assertion evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    state = load_json(state_path)
    evidence_paths.append(str(state_path))

    browser_store = str(browser.get("store") or "")
    state_store = str(state.get("store") or "")
    browser_subscription_id = str(browser.get("subscription_id") or "")
    state_subscription_id = str(state.get("subscription_id") or "")
    expected_subscription = str(expected_subscription_id or "")
    binding_summary = (
        f"expected subscription={expected_subscription or '<missing>'}; "
        f"browser subscription={browser_subscription_id or '<missing>'}; "
        f"state subscription={state_subscription_id or '<missing>'}"
    )

    if expected_subscription_id is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"MS-07 evidence is not bound to an expected subscription fixture for {store}; {binding_summary}",
            "ux_observations": observations + ["Supply the current MS-07 subscription fixture ID before accepting this evidence."],
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    binding_failures = []
    if browser_store != store:
        binding_failures.append(f"browser store={browser_store or '<missing>'}, expected {store}")
    if state_store != store:
        binding_failures.append(f"state store={state_store or '<missing>'}, expected {store}")
    if browser_subscription_id != expected_subscription:
        binding_failures.append("browser subscription does not match the expected fixture")
    if state_subscription_id != expected_subscription:
        binding_failures.append("state subscription does not match the expected fixture")
    if binding_failures:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"MS-07 evidence fixture binding failed for {store}; {binding_summary}",
            "ux_observations": observations + binding_failures,
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    state_success = bool(state.get("success"))
    renewal_status = state.get("renewal_status")
    subscription_status = state.get("subscription_status")
    meta_presence = state.get("renewal_meta_presence") if isinstance(state.get("renewal_meta_presence"), dict) else {}
    token_matches = str(state.get("last_token_id") or "") == selected_token_id
    pm_matches = str(state.get("last_token_pm") or "") == str(state.get("subscription_payment_method_id") or "")
    customer_present = bool(state.get("subscription_stripe_customer_id_present"))
    browser_pass = has_woopayments_method and has_requested_token and token_after_select == selected_token_id and not browser_errors

    observations.append(
        f"Renewal order {state.get('renewal_order_id') or '<unknown>'} finished with status {renewal_status or '<unknown>'}."
    )
    observations.append(
        f"Renewal payment meta presence={meta_presence}."
    )
    observations.append(
        f"Subscription customer meta present={customer_present}; token_matches={token_matches}; payment_method_matches_token={pm_matches}."
    )

    if browser_pass and state_success and token_matches and pm_matches and customer_present:
        verdict = "PASS"
    elif not browser_pass or not state_success:
        verdict = "FAIL - functional"
    else:
        verdict = "BLOCKED"

    return {
        "store": store,
        "verdict": verdict,
        "end_state": (
            f"subscription={state.get('subscription_id')}; selected_token={selected_token_id}; "
            f"last_token={state.get('last_token_id')}; renewal_order={state.get('renewal_order_id')}; "
            f"renewal_status={renewal_status}; subscription_status={subscription_status}"
        ),
        "ux_observations": observations,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
    }


def build_ms07_result(
    reference_browser: Path | None,
    reference_state: Path | None,
    target_browser: Path | None,
    target_state: Path | None,
    reference_subscription_id: int | None,
    target_subscription_id: int | None,
) -> dict[str, Any]:
    ref = ms07_store_result("ref", reference_browser, reference_state, reference_subscription_id)
    target = ms07_store_result("target", target_browser, target_state, target_subscription_id)

    if ref["verdict"].startswith("PASS") and target["verdict"].startswith("PASS"):
        parity = "PASS"
        note = "Reference and target admin subscription payment-method edits rendered saved-token controls, persisted the selected token, and renewed successfully."
    elif ref["verdict"].startswith("FAIL") or target["verdict"].startswith("FAIL"):
        parity = "FAIL - functional"
        note = "At least one store failed the MS-07 admin save or renewal state assertion."
    else:
        parity = "BLOCKED"
        note = "MS-07 admin-change evidence is incomplete."

    return {
        "flow": FLOW_MS07,
        "store_results": [ref, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def build_missing_agent_result(flow: str) -> dict[str, Any]:
    store_results = []
    for store in ("ref", "target"):
        store_results.append(
            {
                "store": store,
                "verdict": "BLOCKED",
                "end_state": f"No Layer-A evidence was available for {flow} on {store}.",
                "ux_observations": [f"Layer-A evidence for {flow} is missing."],
                "visual_diffs": [],
                "evidence_paths": [],
            }
        )

    return {
        "flow": flow,
        "store_results": store_results,
        "parity_verdict": "BLOCKED",
        "regression_note": f"Layer-A evidence for {flow} is missing.",
    }


def copy_agent_result(path: Path, out_dir: Path, context: dict[str, Any]) -> str:
    payload = load_json(path)
    flow = payload.get("flow")
    if not isinstance(flow, str) or not flow:
        raise ValueError(f"{path} does not contain a flow name")
    if not isinstance(payload.get("store_results"), list):
        raise ValueError(f"{path} does not contain store_results")
    if "parity_verdict" not in payload:
        raise ValueError(f"{path} does not contain parity_verdict")
    out_dir.mkdir(parents=True, exist_ok=True)
    try:
        imported = import_captured_result(payload, context, path)
    except EvidenceContextError as exc:
        blocked = build_missing_agent_result(flow)
        blocked["provenance_error"] = {
            "code": exc.code,
            "message": str(exc),
            "source_path": str(path),
        }
        blocked["regression_note"] = f"Layer-A evidence for {flow} is non-gating: {exc.code}."
        imported = stamp_generated_result(blocked, context)
    (out_dir / f"{flow}.json").write_text(
        json.dumps(imported, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return flow


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context-file", required=True, help="Current critical-flow aggregate context JSON.")
    parser.add_argument("--out-dir", required=True, help="Directory for generated Layer-A JSON files.")
    parser.add_argument("--plugin-active-reference-gate", help="Reference plugin-active settings gate JSON.")
    parser.add_argument("--plugin-active-target-gate", help="Target plugin-active settings gate JSON.")
    parser.add_argument("--lpm-gate", help="LPM checkout gate rollup JSON.")
    parser.add_argument("--md01-browser-gate", help="Context-bound MD-01 deterministic/Playwright gate JSON.")
    parser.add_argument("--md02-reference-manifest", help="Context-bound reference MD-02 manifest JSON.")
    parser.add_argument("--md02-target-manifest", help="Context-bound target MD-02 manifest JSON.")
    parser.add_argument("--sc04-reference-browser", help="Reference SC-04 browser evidence JSON.")
    parser.add_argument("--sc04-reference-state", help="Reference SC-04 saved-token/order state JSON.")
    parser.add_argument("--sc04-target-browser", help="Target SC-04 browser evidence JSON.")
    parser.add_argument("--sc04-target-state", help="Target SC-04 saved-token/order state JSON.")
    parser.add_argument(
        "--require-sc04",
        action="store_true",
        help="Write a blocked SC-04 result when raw or prebuilt evidence is unavailable.",
    )
    parser.add_argument("--ms07-reference-browser", help="Reference MS-07 browser result JSON.")
    parser.add_argument("--ms07-reference-state", help="Reference MS-07 state assertion JSON.")
    parser.add_argument("--ms07-target-browser", help="Target MS-07 browser result JSON.")
    parser.add_argument("--ms07-target-state", help="Target MS-07 state assertion JSON.")
    parser.add_argument(
        "--require-ms07",
        action="store_true",
        help="Write a blocked MS-07 result when source evidence is incomplete instead of leaving the flow queued.",
    )
    parser.add_argument("--token-continuity-gate", help="Token continuity gate rollup JSON.")
    parser.add_argument(
        "--copy-agent-result",
        action="append",
        default=[],
        help="Existing Layer-A JSON result to validate and copy into --out-dir.",
    )
    parser.add_argument(
        "--require-agent-flow",
        action="append",
        default=[],
        help="Flow ID that must have Layer-A JSON; writes a blocked result when no generated/copied result exists.",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    out_dir = Path(args.out_dir)
    context = load_json(Path(args.context_file))
    validate_context(context)
    written_flows: set[str] = set()

    for source in args.copy_agent_result:
        written_flows.add(copy_agent_result(Path(source), out_dir, context))

    if args.plugin_active_reference_gate or args.plugin_active_target_gate:
        write_result(
            out_dir,
            FLOW_MA11,
            build_plugin_active_result(
                existing_path(args.plugin_active_reference_gate),
                existing_path(args.plugin_active_target_gate),
                context,
            ),
            context,
        )
        written_flows.add(FLOW_MA11)

    if args.lpm_gate:
        write_result(out_dir, FLOW_SC14, build_lpm_result(existing_path(args.lpm_gate)), context)
        written_flows.add(FLOW_SC14)

    if args.md01_browser_gate:
        write_result(
            out_dir,
            FLOW_MD01,
            build_md01_result(existing_path(args.md01_browser_gate), context),
            context,
        )
        written_flows.add(FLOW_MD01)

    if args.md02_reference_manifest or args.md02_target_manifest:
        write_result(
            out_dir,
            FLOW_MD02,
            build_md02_result(
                existing_path(args.md02_reference_manifest),
                existing_path(args.md02_target_manifest),
                context,
            ),
            context,
            archive_relative=True,
        )
        written_flows.add(FLOW_MD02)

    has_sc04_source = bool(
        args.sc04_reference_browser
        or args.sc04_reference_state
        or args.sc04_target_browser
        or args.sc04_target_state
    )
    if has_sc04_source:
        write_result(
            out_dir,
            FLOW_SC04,
            build_sc04_result(
                existing_path(args.sc04_reference_browser),
                existing_path(args.sc04_reference_state),
                existing_path(args.sc04_target_browser),
                existing_path(args.sc04_target_state),
                context,
            ),
            context,
        )
        written_flows.add(FLOW_SC04)
    elif args.require_sc04 and FLOW_SC04 not in written_flows:
        write_result(
            out_dir,
            FLOW_SC04,
            build_sc04_result(None, None, None, None, context),
            context,
        )
        written_flows.add(FLOW_SC04)

    has_ms07_source = bool(
        args.ms07_reference_browser
        or args.ms07_reference_state
        or args.ms07_target_browser
        or args.ms07_target_state
    )
    if has_ms07_source or (args.require_ms07 and FLOW_MS07 not in written_flows):
        write_result(
            out_dir,
            FLOW_MS07,
            build_ms07_result(
                existing_path(args.ms07_reference_browser),
                existing_path(args.ms07_reference_state),
                existing_path(args.ms07_target_browser),
                existing_path(args.ms07_target_state),
                int(context["fixtures"]["ref"]["subscription_id"]),
                int(context["fixtures"]["target"]["subscription_id"]),
            ),
            context,
        )
        written_flows.add(FLOW_MS07)

    if args.token_continuity_gate:
        write_result(out_dir, FLOW_SS10, build_token_continuity_result(existing_path(args.token_continuity_gate)), context)
        written_flows.add(FLOW_SS10)

    for flow in args.require_agent_flow:
        if flow not in written_flows:
            write_result(out_dir, flow, build_missing_agent_result(flow), context)
            written_flows.add(flow)

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"build-agent-results.py: {exc}", file=sys.stderr)
        raise SystemExit(1)
