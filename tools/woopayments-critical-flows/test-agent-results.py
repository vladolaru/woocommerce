#!/usr/bin/env python3
"""Regression checks for critical-flow Layer-A result generation."""

from __future__ import annotations

import binascii
import hashlib
import hmac
import importlib.util
import json
import os
import struct
import subprocess
import tempfile
import zlib
from pathlib import Path
from typing import Callable


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-critical-flows/build-agent-results.py"
CONTEXT_MODULE_PATH = REPO / "tools/woopayments-critical-flows/evidence_context.py"
MD02_EVIDENCE_PATH = REPO / "tools/woopayments-critical-flows/flows/md02-evidence.py"


def load_context_module():
    spec = importlib.util.spec_from_file_location("critical_flow_evidence_context", CONTEXT_MODULE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONTEXT_MODULE = load_context_module()


def load_md02_module():
    spec = importlib.util.spec_from_file_location("md02_evidence", MD02_EVIDENCE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def builder_context(out_dir: Path, subscriptions: tuple[str, str] = ("862", "366")) -> tuple[Path, dict]:
    path = out_dir.parent / "critical-flow-context.json"
    context = CONTEXT_MODULE.build_context(
        aggregate_run_id="test-run",
        source={"head_sha": "a" * 40, "worktree_sha256": "sha256:" + "b" * 64},
        stores={
            "ref": {
                "store_fingerprint": "sha256:" + "c" * 64,
                "runtime_owner": "plugin",
                "account_state_sha256": "sha256:" + "d" * 64,
            },
            "target": {
                "store_fingerprint": "sha256:" + "e" * 64,
                "runtime_owner": "native",
                "account_state_sha256": "sha256:" + "f" * 64,
            },
        },
        fixtures={
            "ref": {"subscription_id": subscriptions[0]},
            "target": {"subscription_id": subscriptions[1]},
        },
    )
    write_json(path, context)
    return path, context


def run_builder(
    *args: str,
    context_subscriptions: tuple[str, str] = ("862", "366"),
    extra_env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    out_dir = Path(args[args.index("--out-dir") + 1])
    context_path, _ = builder_context(out_dir, context_subscriptions)
    return subprocess.run(
        ["python3", str(SCRIPT), "--context-file", str(context_path), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, **(extra_env or {})},
    )


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def md01_seal(payload: dict, key: str, domain: bytes) -> dict:
    sealed = dict(payload)
    material = json.dumps(sealed, sort_keys=True, separators=(",", ":")).encode()
    sealed["context_hmac"] = "hmac-sha256:" + hmac.new(bytes.fromhex(key), domain + material, hashlib.sha256).hexdigest()
    unsigned = dict(sealed)
    encoded = json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode()
    sealed["payload_sha256"] = "sha256:" + hashlib.sha256(encoded).hexdigest()
    return sealed


def write_md01_packet(base: Path, context: dict, key: str = "42" * 32) -> Path:
    base.mkdir(parents=True, exist_ok=True)
    run_stamp = "20260719T140000Z-4242"
    manifests: dict[str, dict[str, str]] = {}
    results = []
    for index, store in enumerate(("ref", "target"), start=1):
        manifest = base / f"{store}-manifest.json"
        write_json(manifest, {"fixture": store, "run_stamp": run_stamp})
        manifest_digest = "sha256:" + hashlib.sha256(manifest.read_bytes()).hexdigest()
        manifests[store] = {"path": str(manifest), "sha256": manifest_digest}
        screenshots = {}
        for surface in ("order", "disputes"):
            screenshot = base / f"{store}-{surface}.png"
            screenshot.write_bytes(b"\x89PNG\r\n\x1a\n" + bytes([index]) * 32)
            screenshots[screenshot.name] = "sha256:" + hashlib.sha256(screenshot.read_bytes()).hexdigest()
        browser = md01_seal(
            {
                "schema": "woopayments_md01_browser.v1",
                "status": "pass",
                "store": store,
                "run_stamp": run_stamp,
                "runtime_owner": "plugin" if store == "ref" else "native",
                "deterministic_manifest_sha256": manifest_digest,
                "identity": {
                    "order_id": 100 + index,
                    "charge_id": f"ch_{store}_md01",
                    "intent_id": f"pi_{store}_md01",
                    "dispute_id": f"dp_{store}_md01",
                },
                "assertions": {
                    "authenticated_admin": True,
                    "order_on_hold": True,
                    "created_note": True,
                    "note_reason_context": True,
                    "note_response_due_context": True,
                    "exact_dispute_row": True,
                    "needs_response": True,
                    "amount": True,
                    "reason": True,
                    "respond_action": True,
                    "badge_count": True,
                },
                "failed_responses": [],
                "console_errors": [],
                "page_errors": [],
                "screenshots": screenshots,
                "errors": [],
                "blockers": [],
                "raw_sha256": "sha256:" + str(index) * 64,
            },
            key,
            b"woopayments-md01-browser-context-v1\0",
        )
        browser_path = base / f"{store}-browser.json"
        write_json(browser_path, browser)
        log_path = base / f"{store}-browser.log"
        log_path.write_text("Playwright capture complete\n", encoding="utf-8")
        results.append(
            {
                "store": store,
                "status": "pass",
                "browser_path": str(browser_path),
                "browser_sha256": "sha256:" + hashlib.sha256(browser_path.read_bytes()).hexdigest(),
                "log_path": str(log_path),
            }
        )
    gate = md01_seal(
        {
            "schema": "woopayments_md01_browser_gate.v1",
            "status": "pass",
            "run_stamp": run_stamp,
            "context_binding": {
                "aggregate_run_id": context["aggregate_run_id"],
                "context_sha256": context["context_sha256"],
            },
            "deterministic_manifests": manifests,
            "results": results,
            "failures": [],
            "blockers": [],
            "cleanup_failures": [],
        },
        key,
        b"woopayments-md01-browser-gate-context-v1\0",
    )
    gate_path = base / "md01-browser-gate.json"
    write_json(gate_path, gate)
    return gate_path


def write_md02_manifests(
    base: Path,
    context: dict,
    *,
    target_ux_fail: bool = False,
    target_blocked: bool = False,
    key: str = "42" * 32,
) -> tuple[Path, Path]:
    module = load_md02_module()
    old_key = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY")
    os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = key
    run_stamp = "20260719T220000Z-4242"
    packets = {}
    manifests = {}
    try:
        for index, store in enumerate(("ref", "target"), start=1):
            store_dir = base / store
            store_dir.mkdir(parents=True, exist_ok=True)
            source_manifest = store_dir / f"{store}-md01-source-manifest.json"
            write_json(source_manifest, {"schema": "test-md01-source.v1", "store": store})
            identity = {
                "order_id": 100 + index,
                "charge_id": f"ch_{store}_md02",
                "intent_id": f"pi_{store}_md02",
                "dispute_id": f"du_{store}_md02",
            }

            def state(phase: str, saved: bool) -> dict:
                decisive = "4" if saved else "3"
                return {
                    "schema": "woopayments_md02_state.v1",
                    "store": store,
                    "run_stamp": run_stamp,
                    "phase": phase,
                    "runtime_owner": "plugin" if store == "ref" else "native",
                    "identity": identity,
                    "lifecycle": {
                        "status": "needs_response",
                        "due_by": 1785196799,
                        "past_due": False,
                        "has_evidence": saved,
                        "submission_count": 0,
                    },
                    "evidence": {
                        "product_description": module.DESCRIPTION if saved else "prior description",
                        "customer_name_present": True,
                        "customer_name_matches_order": True,
                        "customer_name_hmac": "hmac-sha256:" + "1" * 64,
                        "file_evidence_hmac": "hmac-sha256:" + "2" * 64,
                        "decisive_state_hmac": "hmac-sha256:" + decisive * 64,
                    },
                    "metadata_keys": [],
                    "blockers": [],
                }

            screenshots = {}
            for surface in ("form", "saved", "reloaded"):
                screenshot = store_dir / f"{store}-md02-{surface}.png"
                write_test_png(screenshot, width=800, height=450)
                screenshots[screenshot.name] = "sha256:" + hashlib.sha256(screenshot.read_bytes()).hexdigest()
            partial_screenshots = dict(list(screenshots.items())[:1])
            ux_fail = store == "target" and target_ux_fail
            blocked = store == "target" and target_blocked
            browser = {
                "schema": "woopayments_md02_browser.v1",
                "store": store,
                "run_stamp": run_stamp,
                "runtime_owner": "plugin" if store == "ref" else "native",
                "phase": "unavailable" if blocked else "reloaded",
                "identity": {
                    "order_id": identity["order_id"],
                    "charge_id": identity["charge_id"],
                    "dispute_id": identity["dispute_id"],
                },
                "facts": {
                    "authenticatedAdmin": not blocked,
                    "exactDisputeRow": not blocked,
                    "responseActionDiscovered": not blocked,
                    "requestSeen": not blocked,
                    "requestMethod": "" if blocked else "POST",
                    "requestPathMatches": not blocked,
                    "submitFalse": not blocked,
                    "descriptionMatches": not blocked,
                    "payloadCustomerNameMatches": not blocked,
                    "noFilesAttached": not blocked,
                    "responseOk": not blocked,
                    "saveFeedback": not blocked,
                    "reloadedDescriptionMatches": not blocked,
                    "descriptionEditable": not blocked,
                    "saveButtonLabel": "" if blocked else "Save draft" if ux_fail else "Save for later",
                    "customerNameVisible": not ux_fail and not blocked,
                },
                "functional_assertions": {
                    name: not blocked for name in module.FUNCTIONAL_ASSERTIONS
                },
                "ux_assertions": {
                    "save_for_later_copy": not ux_fail and not blocked,
                    "customer_name_visible": not ux_fail and not blocked,
                },
                "request": {
                    "method": "" if blocked else "POST",
                    "path": "" if blocked else f"/wp-json/wc/v3/payments/disputes/{identity['dispute_id']}",
                    "submit_false": not blocked,
                    "description_matches": not blocked,
                    "customer_name_matches": not blocked,
                    "files_selected": -1 if blocked else 0,
                },
                "response": {"status": 0 if blocked else 200, "ok": not blocked},
                "failed_responses": [],
                "diagnostics": [],
                "console_errors": [],
                "page_errors": [],
                "screenshots": partial_screenshots if blocked else screenshots,
                "errors": [],
                "blockers": ["browser_not_started"] if blocked else [],
            }
            pre = state("pre", False)
            post = state("post", not blocked)
            delayed = state("delayed", not blocked)
            assertions = module.derive_deterministic_assertions(pre, post, delayed, module.DESCRIPTION)
            browser_status, _ = module.derive_browser_verdict(browser)
            packet = module.seal(
                {
                    "schema": "woopayments_md02_store_packet.v1",
                    "status": "blocked" if blocked else "fail" if ux_fail else "pass",
                    "store": store,
                    "run_stamp": run_stamp,
                    "runtime_owner": "plugin" if store == "ref" else "native",
                    "source_manifest": {
                        "path": source_manifest.name,
                        "sha256": "sha256:" + hashlib.sha256(source_manifest.read_bytes()).hexdigest(),
                        "historical_context_hmac": "unverifiable_without_original_run_key",
                    },
                    "context_binding": {
                        "aggregate_run_id": context["aggregate_run_id"],
                        "context_sha256": context["context_sha256"],
                    },
                    "pre_state": pre,
                    "post_state": post,
                    "delayed_state": delayed,
                    "mutation_boundary": "unchanged_safe_failure" if blocked else "trusted_mutation",
                    "deterministic_assertions": assertions,
                    "deterministic_status": "blocked" if blocked else "pass",
                    "browser": browser,
                    "browser_status": browser_status,
                    "diagnostics": [],
                    "cleanup_failures": [],
                },
                module.STORE_PACKET_DOMAIN,
            )
            packet_path = store_dir / f"{store}-md02-store-packet.json"
            write_json(packet_path, packet)
            packets[store] = (packet, packet_path)

        comparison = module.seal(
            module.compare_store_packets(packets["ref"][0], packets["target"][0], run_stamp),
            module.COMPARISON_DOMAIN,
        )
        comparison_path = base / "target/comparison.json"
        write_json(comparison_path, comparison)
        for store in ("ref", "target"):
            packet, packet_path = packets[store]
            store_dir = base / store
            status = packet["status"]
            exit_code = module.STATUS_EXIT[status]
            log_scan = module.seal(
                {
                    "schema": "woopayments_md02_log_scan.v1",
                    "status": "pass",
                    "store": store,
                    "run_stamp": run_stamp,
                    "flow_id": module.FLOW,
                    "purpose": "clean-debug-log",
                    "exit_code": 0,
                    "scan_observed": True,
                    "match_count": 0,
                    "blocker_code": "",
                    "source_payload_sha256": "sha256:" + str(7 if store == "ref" else 8) * 64,
                },
                module.LOG_DOMAIN,
            )
            log_path = store_dir / f"{store}-log-scan.json"
            write_json(log_path, log_scan)
            execution = module.seal(
                module.build_execution(
                    packet,
                    log_scan,
                    status=status,
                    exit_code=exit_code,
                    verdict_sources=[] if status == "pass" else ["browser_gate_fail"],
                    comparison=comparison if store == "target" else None,
                ),
                module.EXECUTION_DOMAIN,
            )
            execution_path = store_dir / f"{store}-execution.json"
            write_json(execution_path, execution)
            artifact_paths = [packet_path, log_path, execution_path]
            if store == "target":
                artifact_paths.append(comparison_path)
            manifest = module.seal(
                module.build_manifest(
                    store=store,
                    status=status,
                    exit_code=exit_code,
                    run_stamp=run_stamp,
                    run_scope="partial",
                    verdict_sources=[] if status == "pass" else ["browser_gate_fail"],
                    paths=artifact_paths,
                ),
                module.MANIFEST_DOMAIN,
            )
            manifest_path = store_dir / f"{store}-manifest.json"
            write_json(manifest_path, manifest)
            manifests[store] = manifest_path
        return manifests["ref"], manifests["target"]
    finally:
        if old_key is None:
            os.environ.pop("CRITICAL_FLOWS_RUN_CONTEXT_KEY", None)
        else:
            os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = old_key


def write_test_png(path: Path, width: int = 800, height: int = 450) -> None:
    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", binascii.crc32(kind + data) & 0xFFFFFFFF)

    pixels = bytearray()
    value = 0x12345678
    for _row in range(height):
        pixels.append(0)
        for _column in range(width):
            value = (1103515245 * value + 12345) & 0x7FFFFFFF
            pixels.extend(((value >> 16) & 0xFF, (value >> 8) & 0xFF, value & 0xFF))
    path.write_bytes(
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
        + chunk(b"IDAT", zlib.compress(bytes(pixels), level=6))
        + chunk(b"IEND", b"")
    )


def write_blank_test_png(path: Path, width: int = 1440, height: int = 1100) -> None:
    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", binascii.crc32(kind + data) & 0xFFFFFFFF)

    pixels = (b"\x00" + b"\xff\xff\xff" * width) * height
    path.write_bytes(
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
        + chunk(b"IDAT", zlib.compress(pixels, level=9))
        + chunk(b"IEND", b"")
    )


def plugin_active_rollup(role: str, store_url: str, screenshot: str, context_sha256: str) -> dict:
    settings_url = f"{store_url}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments"
    evidence = {
        "schema": "woopayments_plugin_active_settings_browser_evidence.v1",
        "status": "pass",
        "target_url": store_url,
        "settings_url": settings_url,
        "plugin_active": True,
        "authenticated_wp_admin": True,
        "settings_screen_present": True,
        "plugin_settings_assets_present": True,
        "plugin_settings_global_present": True,
        "plugin_settings_script_urls": [
            f"{store_url}/wp-content/plugins/woocommerce-payments/dist/settings.js"
        ],
        "plugin_settings_style_urls": [
            f"{store_url}/wp-content/plugins/woocommerce-payments/dist/settings.css"
        ],
        "native_settings_asset_urls": [],
        "duplicate_store_errors": [],
        "fatal_console_errors": [],
        "all_failed_responses": [],
        "failed_responses": [],
        "blocked_responses": [],
        "blockers": [],
        "failures": [],
        "logs": ["[log] JQMIGRATE: Migrate is installed with logging active, version 3.4.1"],
        "screenshot_path": screenshot,
        "page": {
            "finalUrl": settings_url,
            "hasAdminBody": True,
            "hasAdminMenu": True,
            "hasLoginForm": False,
            "hasWpbodyContent": True,
            "pluginSettingsAssetsPresent": True,
            "pluginSettingsGlobalPresent": True,
            "pluginSettingsScriptUrls": [
                f"{store_url}/wp-content/plugins/woocommerce-payments/dist/settings.js"
            ],
            "pluginSettingsStyleUrls": [
                f"{store_url}/wp-content/plugins/woocommerce-payments/dist/settings.css"
            ],
            "nativeSettingsAssetUrls": [],
            "selectorMatches": [{"count": 1, "selector": "#wcpay-account-settings-container"}],
            "settingsScreenPresent": True,
            "settingsStoreSelectable": True,
        },
    }
    return {
        "schema": "woopayments_plugin_active_settings_gate_rollup.v1",
        "status": "pass",
        "settings_url": settings_url,
        "target_url": store_url,
        "runner_role": role,
        "context_sha256": context_sha256,
        "blockers": [],
        "failures": [],
        "evidence": evidence,
    }


def write_plugin_active_packet(base: Path, role: str, context_sha256: str) -> Path:
    store_url = "http://localhost:8082" if role == "reference" else "http://store8889.localhost:8889"
    base.mkdir(parents=True, exist_ok=True)
    screenshot = base / "plugin-active-settings.png"
    browser = base / "plugin-active-settings.json"
    failures = base / "plugin-active-settings-failures.txt"
    blockers = base / "plugin-active-settings-blockers.txt"
    log = base / "plugin-active-settings.browser.log"
    gate = base / "plugin-active-settings-gate.json"
    write_test_png(screenshot)
    payload = plugin_active_rollup(role, store_url, str(screenshot), context_sha256)
    write_json(browser, payload["evidence"])
    failures.write_text("", encoding="utf-8")
    blockers.write_text("", encoding="utf-8")
    log.write_text("browser capture complete\n", encoding="utf-8")
    artifact_paths = [browser, blockers, failures, log, screenshot]
    if role == "target":
        snapshot = base / "plugin-active-settings-snapshot.json"
        stage = base / "plugin-active-settings-stage.json"
        restore = base / "plugin-active-settings-restore.json"
        write_json(
            snapshot,
            {
                "schema": "woopayments_plugin_active_fixture_snapshot.v1",
                "success": True,
                "mode": "snapshot-plugin-active",
                "errors": [],
                "was_plugin_active": False,
                "candidate_mu_plugins": [
                    {
                        "path": "/var/www/html/wp-content/mu-plugins/native-payments-enable.php",
                        "disabled_path": "/var/www/html/wp-content/mu-plugins/native-payments-enable.php.disabled-by-woopayments-merge",
                        "sha256": "a" * 64,
                    }
                ],
            },
        )
        snapshot_sha256 = f"sha256:{hashlib.sha256(snapshot.read_bytes()).hexdigest()}"
        write_json(
            stage,
            {
                "success": True,
                "mode": "mutate-plugin-active",
                "errors": [],
                "wcpay_plugin_active": True,
                "runtime_owner": "plugin",
                "snapshot_sha256": snapshot_sha256,
            },
        )
        write_json(
            restore,
            {
                "success": True,
                "mode": "restore-plugin-active",
                "errors": [],
                "wcpay_plugin_active": False,
                "runtime_owner": "native",
                "snapshot_sha256": snapshot_sha256,
            },
        )
        artifact_paths.extend((snapshot, stage, restore))
    payload["artifacts"] = [
        {"path": str(path), "sha256": f"sha256:{hashlib.sha256(path.read_bytes()).hexdigest()}"}
        for path in sorted(artifact_paths)
    ]
    write_json(gate, payload)
    return gate


def refresh_plugin_active_manifest(gate: Path) -> None:
    payload = read_json(gate)
    for artifact in payload["artifacts"]:
        path = Path(artifact["path"])
        artifact["sha256"] = f"sha256:{hashlib.sha256(path.read_bytes()).hexdigest()}"
    write_json(gate, payload)


def corrupt_png_idat(path: Path) -> None:
    data = bytearray(path.read_bytes())
    offset = 8
    while offset < len(data):
        length = struct.unpack(">I", data[offset : offset + 4])[0]
        chunk_type = bytes(data[offset + 4 : offset + 8])
        if chunk_type == b"IDAT":
            data[offset + 8] ^= 0xFF
            crc = binascii.crc32(bytes(data[offset + 4 : offset + 8 + length])) & 0xFFFFFFFF
            data[offset + 8 + length : offset + 12 + length] = struct.pack(">I", crc)
            path.write_bytes(data)
            return
        offset += 12 + length
    raise AssertionError("test PNG has no IDAT chunk")


def duplicate_png_ihdr(path: Path) -> None:
    data = path.read_bytes()
    first_chunk_length = struct.unpack(">I", data[8:12])[0]
    first_chunk_end = 8 + 12 + first_chunk_length
    path.write_bytes(data[:first_chunk_end] + data[8:first_chunk_end] + data[first_chunk_end:])


def token_continuity_rollup(screenshot: Path) -> dict:
    return {
        "schema": "woopayments_token_continuity_gate_rollup.v1",
        "status": "pass",
        "source_flow": "provider_setup_intent",
        "token_id": 13,
        "failures": [],
        "blockers": [],
        "source_token": {
            "success": True,
            "payment_method_id": "pm_unit",
            "source_payment_method_customer_ready": True,
        },
        "native_token_loader": {
            "success": True,
            "token_id": 13,
            "gateway_id": "woocommerce_payments_sepa_debit",
            "token_type": "wcpay_sepa",
            "token_class": "WooPaymentsSepaToken",
        },
        "render_payment_methods": {
            "status": "pass",
            "token_id": 13,
            "token_visible": True,
            "page": {
                "screenshot_path": str(screenshot),
                "payment_methods": {
                    "token_visible": True,
                },
            },
        },
        "renewal": {
            "success": True,
            "renewal_order_id": 261,
            "renewal_order_status": "pending",
            "renewal_processing_model": "asynchronous_processing",
            "subscription_status": "on-hold",
            "success_checks_failed": [],
        },
    }


def ms07_browser_result(store: str, evidence_dir: str, subscription_id: int, before: str, selected: str) -> dict:
    return {
        "store": store,
        "subscription_id": subscription_id,
        "selected_token_id": selected,
        "evidence_dir": evidence_dir,
        "errors": [],
        "method_options": [
            {"value": "manual", "text": "Manual Renewal", "selected": False},
            {"value": "woocommerce_payments", "text": "Card", "selected": True},
        ],
        "token_options_before": [
            {"value": before, "text": "Visa ending in 4242", "selected": True},
            {"value": selected, "text": "Visa ending in 4242", "selected": False},
        ],
        "token_value_before": before,
        "token_value_after_select": selected,
        "notice_text": ["Subscription updated."],
    }


def ms07_state_result(store: str, subscription_id: int, token_id: int, renewal_order_id: int) -> dict:
    return {
        "store": store,
        "success": True,
        "subscription_id": subscription_id,
        "expected_token_id": token_id,
        "renewal_order_id": renewal_order_id,
        "subscription_status": "active",
        "subscription_payment_method": "woocommerce_payments",
        "subscription_payment_method_id": f"pm_{store}_{token_id}",
        "subscription_stripe_customer_id_present": True,
        "subscription_token_ids": [token_id - 1, token_id],
        "last_token_id": token_id,
        "last_token_pm": f"pm_{store}_{token_id}",
        "renewal_status": "processing",
        "renewal_payment_method": "woocommerce_payments",
        "renewal_meta_presence": {
            "_intent_id": True,
            "_charge_id": True,
            "_payment_method_id": True,
            "_stripe_customer_id": True,
        },
    }


def sc04_browser_result(
    context: dict,
    store: str,
    home_url: str,
    customer_id: int,
    token_id: int,
    order_ids: tuple[int, int, int, int],
    screenshots: tuple[Path, Path, Path, Path],
) -> dict:
    surfaces = {}
    surface_names = ("classic", "blocks", "sca_classic", "sca_blocks")
    sca_token_id = token_id + 1
    for surface, order_id, screenshot in zip(surface_names, order_ids, screenshots, strict=True):
        requires_sca = surface.startswith("sca_")
        surfaces[surface] = {
            "success": True,
            "order_id": order_id,
            "selected_token_id": sca_token_id if requires_sca else token_id,
            "final_url": f"{home_url}/checkout/order-received/{order_id}/?key=wc_order_test",
            "saved_card_visible": True,
            "saved_card_selected": True,
            "new_card_fields_forced": False,
            "sca_challenge_present": requires_sca,
            "sca_challenge_completed": requires_sca,
            "expected_amount": "20.00",
            "expected_currency": "USD",
            "screenshot_paths": [str(screenshot)],
            "errors": [],
        }
    return {
        "schema": "woopayments_sc04_browser_evidence.v1",
        "context_binding": {
            "aggregate_run_id": context["aggregate_run_id"],
            "context_sha256": context["context_sha256"],
        },
        "store": store,
        "home_url": home_url,
        "customer_id": customer_id,
        "token_id": token_id,
        "sca_token_id": sca_token_id,
        "surfaces": surfaces,
        "fatal_console_errors": [],
        "fatal_response_errors": [],
        "errors": [],
    }


def sc04_state_result(
    context: dict,
    store: str,
    home_url: str,
    customer_id: int,
    token_id: int,
    order_ids: tuple[int, int, int, int],
) -> dict:
    payment_method_id = f"pm_{store}_{token_id}"
    sca_token_id = token_id + 1
    sca_payment_method_id = f"pm_{store}_{sca_token_id}"
    orders = {}
    for surface, order_id in zip(("classic", "blocks", "sca_classic", "sca_blocks"), order_ids, strict=True):
        requires_sca = surface.startswith("sca_")
        orders[surface] = {
            "success": True,
            "order_id": order_id,
            "customer_id": customer_id,
            "status": "processing",
            "payment_method": "woocommerce_payments",
            "payment_method_id": sca_payment_method_id if requires_sca else payment_method_id,
            "amount": "20.00",
            "currency": "USD",
            "meta_presence": {
                "_intent_id": True,
                "_charge_id": True,
            },
            "errors": [],
        }
    return {
        "schema": "woopayments_sc04_state_evidence.v1",
        "context_binding": {
            "aggregate_run_id": context["aggregate_run_id"],
            "context_sha256": context["context_sha256"],
        },
        "store": store,
        "home_url": home_url,
        "source": context["source"],
        "store_context": context["stores"][store],
        "customer_id": customer_id,
        "token": {
            "id": token_id,
            "user_id": customer_id,
            "gateway_id": "woocommerce_payments",
            "payment_method_id": payment_method_id,
        },
        "sca_token": {
            "id": sca_token_id,
            "user_id": customer_id,
            "gateway_id": "woocommerce_payments",
            "payment_method_id": sca_payment_method_id,
        },
        "orders": orders,
        "errors": [],
    }


def test_builds_plugin_active_agent_result_from_reference_and_target_gates() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _context_path, context = builder_context(out_dir)
        ref_gate = write_plugin_active_packet(tmp_path / "reference", "reference", context["context_sha256"])
        target_gate = write_plugin_active_packet(tmp_path / "target", "target", context["context_sha256"])

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--plugin-active-reference-gate",
            str(ref_gate),
            "--plugin-active-target-gate",
            str(target_gate),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MA-11-plugin-active-settings-screen.json")
        assert payload["flow"] == "MA-11-plugin-active-settings-screen"
        assert payload["parity_verdict"] == "PASS"
        assert [store["verdict"] for store in payload["store_results"]] == ["PASS", "PASS"]
        assert all(store["evidence"] for store in payload["store_results"])


def test_builds_plugin_active_result_from_legacy_browser_log_packets() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def use_legacy_log_name(ref_gate: Path, target_gate: Path, _context: dict) -> None:
            for gate in (ref_gate, target_gate):
                payload = read_json(gate)
                for artifact in payload["artifacts"]:
                    path = Path(artifact["path"])
                    if path.name != "plugin-active-settings.browser.log":
                        continue
                    legacy_path = path.with_name("plugin-active-settings.playwriter.log")
                    path.rename(legacy_path)
                    artifact["path"] = str(legacy_path)
                    artifact["sha256"] = f"sha256:{hashlib.sha256(legacy_path.read_bytes()).hexdigest()}"
                write_json(gate, payload)

        result, payload = build_plugin_active_test_result(tmp_path, use_legacy_log_name)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "PASS"


def build_plugin_active_test_result(
    tmp_path: Path,
    mutate: Callable[[Path, Path, dict], None] | None = None,
) -> tuple[subprocess.CompletedProcess[str], dict]:
    out_dir = tmp_path / "agent-results"
    _context_path, context = builder_context(out_dir)
    ref_gate = write_plugin_active_packet(tmp_path / "reference", "reference", context["context_sha256"])
    target_gate = write_plugin_active_packet(tmp_path / "target", "target", context["context_sha256"])
    if mutate:
        mutate(ref_gate, target_gate, context)
    result = run_builder(
        "--out-dir",
        str(out_dir),
        "--plugin-active-reference-gate",
        str(ref_gate),
        "--plugin-active-target-gate",
        str(target_gate),
    )
    return result, read_json(out_dir / "MA-11-plugin-active-settings-screen.json")


def test_plugin_active_result_rejects_swapped_store_roles() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def swap_roles(ref_gate: Path, target_gate: Path, _context: dict) -> None:
            ref = read_json(ref_gate)
            target = read_json(target_gate)
            ref["runner_role"], target["runner_role"] = target["runner_role"], ref["runner_role"]
            write_json(ref_gate, ref)
            write_json(target_gate, target)

        result, payload = build_plugin_active_test_result(tmp_path, swap_roles)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]


def test_plugin_active_result_rejects_contradictory_passing_browser_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def hide_settings_screen(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            payload["evidence"]["settings_screen_present"] = False
            write_json(target_gate, payload)

        result, payload = build_plugin_active_test_result(tmp_path, hide_settings_screen)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_stale_context_binding() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def stale_context(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            payload["context_sha256"] = "sha256:" + "0" * 64
            write_json(target_gate, payload)

        result, payload = build_plugin_active_test_result(tmp_path, stale_context)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_browser_rollup_raw_mismatch() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def tamper_embedded_browser(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            payload["evidence"]["page"]["settingsStoreSelectable"] = False
            write_json(target_gate, payload)

        result, payload = build_plugin_active_test_result(tmp_path, tamper_embedded_browser)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_failed_target_restore() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def fail_restore(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            restore = target_gate.parent / "plugin-active-settings-restore.json"
            payload = read_json(restore)
            payload["success"] = False
            payload["errors"] = ["fixture did not restore"]
            write_json(restore, payload)

        result, payload = build_plugin_active_test_result(tmp_path, fail_restore)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_empty_target_native_candidate_set() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def empty_candidate(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            snapshot = target_gate.parent / "plugin-active-settings-snapshot.json"
            payload = read_json(snapshot)
            payload["candidate_mu_plugins"] = []
            write_json(snapshot, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, empty_candidate)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_malformed_target_native_candidate() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def malformed_candidate(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            snapshot = target_gate.parent / "plugin-active-settings-snapshot.json"
            payload = read_json(snapshot)
            payload["candidate_mu_plugins"] = [
                {
                    "path": "../native-payments-enable.php",
                    "disabled_path": "../native-payments-enable.php.disabled",
                    "sha256": "not-a-digest",
                }
            ]
            write_json(snapshot, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, malformed_candidate)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_lifecycle_snapshot_digest_mismatch() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def wrong_snapshot_digest(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            stage = target_gate.parent / "plugin-active-settings-stage.json"
            payload = read_json(stage)
            payload["snapshot_sha256"] = "sha256:" + "0" * 64
            write_json(stage, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, wrong_snapshot_digest)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_wrong_staged_runtime_owner() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def wrong_runtime_owner(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            stage = target_gate.parent / "plugin-active-settings-stage.json"
            payload = read_json(stage)
            payload["runtime_owner"] = "native"
            write_json(stage, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, wrong_runtime_owner)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_artifact_hash_mismatch() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def tamper_log(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            (target_gate.parent / "plugin-active-settings.browser.log").write_text(
                "tampered after capture\n", encoding="utf-8"
            )

        result, payload = build_plugin_active_test_result(tmp_path, tamper_log)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_invalid_screenshot() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def truncate_screenshot(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            (target_gate.parent / "plugin-active-settings.png").write_bytes(b"not a screenshot")

        result, payload = build_plugin_active_test_result(tmp_path, truncate_screenshot)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_png_with_invalid_image_data() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def corrupt_screenshot(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            corrupt_png_idat(target_gate.parent / "plugin-active-settings.png")
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, corrupt_screenshot)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_png_with_duplicate_ihdr() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def duplicate_ihdr(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            duplicate_png_ihdr(target_gate.parent / "plugin-active-settings.png")
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, duplicate_ihdr)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_cross_field_asset_mismatch() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def contradict_assets(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            payload["evidence"]["page"]["pluginSettingsScriptUrls"] = []
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, contradict_assets)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_settings_asset_suffix_confusion() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def confuse_asset_suffixes(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            script = (
                "http://store8889.localhost:8889/wp-content/plugins/woocommerce-payments/"
                "dist/settings-attacker.css?fake=.js"
            )
            style = (
                "http://store8889.localhost:8889/wp-content/plugins/woocommerce-payments/"
                "dist/settings-attacker.js?fake=.css"
            )
            payload["evidence"]["plugin_settings_script_urls"] = [script]
            payload["evidence"]["plugin_settings_style_urls"] = [style]
            payload["evidence"]["page"]["pluginSettingsScriptUrls"] = [script]
            payload["evidence"]["page"]["pluginSettingsStyleUrls"] = [style]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, confuse_asset_suffixes)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_fatal_token_smuggled_in_console_log() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def smuggle_fatal_log(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            payload["evidence"]["logs"] = [
                "[log] JQMIGRATE: Migrate is installed with logging active; Uncaught TypeError"
            ]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, smuggle_fatal_log)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_structured_console_error() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def smuggle_console_error(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            payload["evidence"]["logs"] = [
                {"type": "error", "text": "A generic browser console problem"}
            ]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, smuggle_console_error)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_arbitrary_fatal_console_array_entry() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def fake_fatal_entry(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "fatal browser console errors were captured"
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["fatal_console_errors"] = [
                {"type": "log", "text": "ordinary console note"}
            ]
            payload["evidence"]["failures"] = [failure]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, fake_fatal_entry)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_preserves_valid_product_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def record_blank_screen(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "WooPayments settings screen is not present"
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["settings_screen_present"] = False
            payload["evidence"]["page"]["settingsScreenPresent"] = False
            payload["evidence"]["failures"] = [failure]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, record_blank_screen)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "FAIL - UX"
        assert payload["store_results"][1]["verdict"] == "FAIL - functional"


def test_plugin_active_result_preserves_native_asset_hijack_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def record_native_hijack(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "native WooPayments settings assets were observed"
            native_asset = (
                "http://store8889.localhost:8889/wp-content/plugins/woocommerce/"
                "assets/client/admin/chunks/settings-payments-woopayments.js"
            )
            payload["status"] = "fail"
            payload["failures"] = ["browser evidence reported failure status", failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["native_settings_asset_urls"] = [native_asset]
            payload["evidence"]["page"]["nativeSettingsAssetUrls"] = [native_asset]
            payload["evidence"]["failures"] = [failure]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                "\n".join(payload["failures"]) + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, record_native_hijack)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "FAIL - UX"
        assert payload["store_results"][1]["verdict"] == "FAIL - functional"


def test_plugin_active_result_rejects_arbitrary_native_asset_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def fake_native_asset(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "native WooPayments settings assets were observed"
            arbitrary_asset = "http://store8889.localhost:8889/wp-content/themes/store/style.css"
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["native_settings_asset_urls"] = [arbitrary_asset]
            payload["evidence"]["page"]["nativeSettingsAssetUrls"] = [arbitrary_asset]
            payload["evidence"]["failures"] = [failure]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, fake_native_asset)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_unbacked_self_declared_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def declare_failure(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "browser evidence reported failure status"
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["failures"] = [failure]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, declare_failure)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_nonfailure_response_as_product_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def fake_failed_response(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "failed browser responses were captured"
            response = {
                "url": (
                    "http://store8889.localhost:8889/wp-admin/admin.php?"
                    "page=wc-settings&tab=checkout&section=woocommerce_payments"
                ),
                "status": 200,
                "statusText": "OK",
            }
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["failed_responses"] = [response]
            payload["evidence"]["all_failed_responses"] = [response]
            payload["evidence"]["failures"] = [failure]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, fake_failed_response)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_treats_unauthenticated_capture_as_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def expire_session(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failures = [
                "authenticated wp-admin settings page did not render",
                "WooPayments settings screen is not present",
            ]
            payload["status"] = "fail"
            payload["failures"] = failures
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["authenticated_wp_admin"] = False
            payload["evidence"]["settings_screen_present"] = False
            payload["evidence"]["failures"] = failures
            payload["evidence"]["page"]["finalUrl"] = (
                "http://store8889.localhost:8889/wp-login.php"
            )
            payload["evidence"]["page"]["hasLoginForm"] = True
            payload["evidence"]["page"]["settingsScreenPresent"] = False
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                "\n".join(failures) + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, expire_session)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_contradictory_authenticated_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def contradict_authentication(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "WooPayments settings screen is not present"
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["settings_screen_present"] = False
            payload["evidence"]["failures"] = [failure]
            payload["evidence"]["page"]["settingsScreenPresent"] = False
            payload["evidence"]["page"]["hasLoginForm"] = True
            payload["evidence"]["page"]["hasAdminBody"] = False
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, contradict_authentication)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_rejects_contradictory_asset_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def contradict_assets(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "standalone WooPayments settings assets were not observed"
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["plugin_settings_assets_present"] = False
            payload["evidence"]["page"]["pluginSettingsAssetsPresent"] = False
            payload["evidence"]["failures"] = [failure]
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, contradict_assets)

        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][1]["verdict"] == "BLOCKED"


def test_plugin_active_result_preserves_blank_screen_product_failure_with_small_valid_png() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)

        def record_blank_screen(_ref_gate: Path, target_gate: Path, _context: dict) -> None:
            payload = read_json(target_gate)
            failure = "WooPayments settings screen is not present"
            payload["status"] = "fail"
            payload["failures"] = [failure]
            payload["evidence"]["status"] = "fail"
            payload["evidence"]["settings_screen_present"] = False
            payload["evidence"]["page"]["settingsScreenPresent"] = False
            payload["evidence"]["failures"] = [failure]
            write_blank_test_png(target_gate.parent / "plugin-active-settings.png")
            write_json(target_gate.parent / "plugin-active-settings.json", payload["evidence"])
            (target_gate.parent / "plugin-active-settings-failures.txt").write_text(
                failure + "\n", encoding="utf-8"
            )
            write_json(target_gate, payload)
            refresh_plugin_active_manifest(target_gate)

        result, payload = build_plugin_active_test_result(tmp_path, record_blank_screen)

        assert (target_png := tmp_path / "target" / "plugin-active-settings.png").stat().st_size < 20_000
        assert target_png.stat().st_size > 0
        assert result.returncode == 0, result.stderr
        assert payload["parity_verdict"] == "FAIL - UX"
        assert payload["store_results"][1]["verdict"] == "FAIL - functional"


def test_lpm_gate_blocked_result_stays_blocked_with_method_context() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        lpm_gate = tmp_path / "lpm-checkout-gate.json"
        write_json(
            lpm_gate,
            {
                "schema": "woopayments_lpm_checkout_gate.v1",
                "status": "blocked",
                "failures": [],
                "blockers": [
                    "reference/p24: could not stage LPM fixture",
                    "target/p24: could not stage LPM fixture",
                ],
                "results": [
                    {
                        "role": "reference",
                        "method": "ideal",
                        "status": "pass",
                        "gateway_id": "woocommerce_payments_ideal",
                    },
                    {
                        "role": "target",
                        "method": "ideal",
                        "status": "pass",
                        "gateway_id": "woocommerce_payments_ideal",
                    },
                ],
            },
        )

        result = run_builder("--out-dir", str(out_dir), "--lpm-gate", str(lpm_gate))

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-14-lpm-wave-1-checkout.json")
        assert payload["flow"] == "SC-14-lpm-wave-1-checkout"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "Runnable methods passed: ideal." in payload["store_results"][0]["ux_observations"]
        assert "target/p24: could not stage LPM fixture" in payload["store_results"][1]["ux_observations"]


def test_token_continuity_pass_maps_to_ss10_agent_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        token_gate = tmp_path / "token-continuity-gate.json"
        screenshot = tmp_path / "payment-methods.png"
        screenshot.write_bytes(b"payment methods screenshot")
        write_json(token_gate, token_continuity_rollup(screenshot))

        result = run_builder("--out-dir", str(out_dir), "--token-continuity-gate", str(token_gate))

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SS-10-sepa-token-renewal-cutover.json")
        assert payload["flow"] == "SS-10-sepa-token-renewal-cutover"
        assert payload["oracle_mode"] == "target-only"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "PASS"]
        assert "target-only" in payload["regression_note"].lower()
        assert "token_visible=True" in payload["store_results"][1]["end_state"]
        assert str(screenshot) in {
            artifact["path"] for artifact in payload["store_results"][1]["evidence"]
        }


def test_token_continuity_nominal_pass_requires_each_target_proof() -> None:
    mutations = {
        "source token": lambda payload: payload["source_token"].update(
            source_payment_method_customer_ready=False
        ),
        "native token loader": lambda payload: payload["native_token_loader"].update(success=False),
        "My Account token rendering": lambda payload: payload["render_payment_methods"].update(
            token_visible=False
        ),
        "SEPA renewal": lambda payload: payload["renewal"].update(success=False),
    }

    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        screenshot = tmp_path / "payment-methods.png"
        screenshot.write_bytes(b"payment methods screenshot")

        for case, mutate in mutations.items():
            case_dir = tmp_path / case.replace(" ", "-")
            case_dir.mkdir()
            token_gate = case_dir / "token-continuity-gate.json"
            payload = token_continuity_rollup(screenshot)
            mutate(payload)
            write_json(token_gate, payload)

            result = run_builder(
                "--out-dir",
                str(case_dir / "agent-results"),
                "--token-continuity-gate",
                str(token_gate),
            )

            assert result.returncode == 0, result.stderr
            built = read_json(case_dir / "agent-results" / "SS-10-sepa-token-renewal-cutover.json")
            assert built["oracle_mode"] == "target-only", case
            assert built["parity_verdict"] == "BLOCKED", case
            assert [store["verdict"] for store in built["store_results"]] == ["BLOCKED", "BLOCKED"], case
            assert any(
                case in observation for observation in built["store_results"][1]["ux_observations"]
            ), case


def test_ms07_browser_and_state_results_map_to_agent_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        ref_browser = tmp_path / "ref-browser.json"
        ref_state = tmp_path / "ref-state.json"
        target_browser = tmp_path / "target-browser.json"
        target_state = tmp_path / "target-state.json"
        ref_evidence = tmp_path / "ref-evidence"
        target_evidence = tmp_path / "target-evidence"
        ref_evidence.mkdir()
        target_evidence.mkdir()
        (target_evidence / "selected.png").write_text("fake", encoding="utf-8")
        write_json(ref_browser, ms07_browser_result("ref", str(ref_evidence), 862, "31", "36"))
        write_json(ref_state, ms07_state_result("ref", 862, 36, 863))
        write_json(target_browser, ms07_browser_result("target", str(target_evidence), 366, "14", "15"))
        write_json(target_state, ms07_state_result("target", 366, 15, 367))

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--ms07-reference-browser",
            str(ref_browser),
            "--ms07-reference-state",
            str(ref_state),
            "--ms07-target-browser",
            str(target_browser),
            "--ms07-target-state",
            str(target_state),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MS-07-admin-change-method.json")
        assert payload["flow"] == "MS-07-admin-change-method"
        assert payload["parity_verdict"] == "PASS"
        assert [store["verdict"] for store in payload["store_results"]] == ["PASS", "PASS"]
        assert "selected_token=15" in payload["store_results"][1]["end_state"]
        assert str(target_evidence / "selected.png") in {
            artifact["path"] for artifact in payload["store_results"][1]["evidence"]
        }


def test_ms07_stale_subscription_sources_are_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        ref_browser = tmp_path / "ref-browser.json"
        ref_state = tmp_path / "ref-state.json"
        target_browser = tmp_path / "target-browser.json"
        target_state = tmp_path / "target-state.json"
        write_json(ref_browser, ms07_browser_result("ref", str(tmp_path / "ref"), 862, "31", "36"))
        write_json(ref_state, ms07_state_result("ref", 862, 36, 863))
        write_json(target_browser, ms07_browser_result("target", str(tmp_path / "target"), 366, "14", "15"))
        write_json(target_state, ms07_state_result("target", 366, 15, 367))

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--ms07-reference-browser",
            str(ref_browser),
            "--ms07-reference-state",
            str(ref_state),
            "--ms07-target-browser",
            str(target_browser),
            "--ms07-target-state",
            str(target_state),
            context_subscriptions=("1283", "874"),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MS-07-admin-change-method.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "expected subscription=1283" in payload["store_results"][0]["end_state"]
        assert "browser subscription=862" in payload["store_results"][0]["end_state"]
        assert "expected subscription=874" in payload["store_results"][1]["end_state"]
        assert "state subscription=366" in payload["store_results"][1]["end_state"]


def test_required_ms07_without_source_writes_blocked_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"

        result = run_builder("--out-dir", str(out_dir), "--require-ms07")

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MS-07-admin-change-method.json")
        assert payload["flow"] == "MS-07-admin-change-method"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "browser evidence" in payload["store_results"][0]["end_state"]


def test_sc04_browser_and_state_results_map_to_agent_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        sources = {}
        fixtures = {
            "ref": ("http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304)),
            "target": ("http://store8889.localhost:8889", 3, 16, (901, 902, 903, 904)),
        }
        for store, (home_url, customer_id, token_id, order_ids) in fixtures.items():
            browser = tmp_path / f"{store}-sc04-browser.json"
            state = tmp_path / f"{store}-sc04-state.json"
            screenshots = (
                tmp_path / f"{store}-classic-selected.png",
                tmp_path / f"{store}-blocks-selected.png",
                tmp_path / f"{store}-sca-classic-selected.png",
                tmp_path / f"{store}-sca-blocks-selected.png",
            )
            for screenshot in screenshots:
                screenshot.write_bytes(f"{store} screenshot".encode())
            write_json(
                browser,
                sc04_browser_result(context, store, home_url, customer_id, token_id, order_ids, screenshots),
            )
            write_json(
                state,
                sc04_state_result(context, store, home_url, customer_id, token_id, order_ids),
            )
            sources[store] = (browser, state)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-reference-browser",
            str(sources["ref"][0]),
            "--sc04-reference-state",
            str(sources["ref"][1]),
            "--sc04-target-browser",
            str(sources["target"][0]),
            "--sc04-target-state",
            str(sources["target"][1]),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["flow"] == "SC-04-saved-card"
        assert payload["parity_verdict"] == "PASS"
        assert [store["verdict"] for store in payload["store_results"]] == ["PASS", "PASS"]
        assert "classic_order=901" in payload["store_results"][1]["end_state"]
        assert "blocks_order=902" in payload["store_results"][1]["end_state"]
        assert str(tmp_path / "target-blocks-selected.png") in {
            artifact["path"] for artifact in payload["store_results"][1]["evidence"]
        }


def test_sc04_stale_store_or_source_context_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "ref-sc04-browser.json"
        state = tmp_path / "ref-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        write_json(browser, sc04_browser_result(context, "ref", "http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304), screenshots))
        stale = sc04_state_result(context, "ref", "http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304))
        stale["source"] = {**stale["source"], "worktree_sha256": "sha256:" + "0" * 64}
        write_json(state, stale)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-reference-browser",
            str(browser),
            "--sc04-reference-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][0]["verdict"] == "BLOCKED"
        assert "source snapshot does not match" in payload["store_results"][0]["ux_observations"]


def test_sc04_complete_saved_card_regression_is_a_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        browser_payload["surfaces"]["blocks"]["success"] = False
        browser_payload["surfaces"]["blocks"]["new_card_fields_forced"] = True
        browser_payload["surfaces"]["blocks"]["errors"] = ["empty new-card Element was forced"]
        write_json(browser, browser_payload)
        write_json(
            state,
            sc04_state_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
            ),
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "FAIL - functional"
        assert payload["store_results"][1]["verdict"] == "FAIL - functional"
        assert "empty new-card Element was forced" in payload["store_results"][1]["ux_observations"]


def test_sc04_context_binding_mismatch_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "ref-sc04-browser.json"
        state = tmp_path / "ref-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "ref",
            "http://localhost:8082",
            17,
            37,
            (1301, 1302, 1303, 1304),
            screenshots,
        )
        browser_payload["context_binding"]["aggregate_run_id"] = "older-run"
        write_json(browser, browser_payload)
        write_json(
            state,
            sc04_state_result(context, "ref", "http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304)),
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-reference-browser",
            str(browser),
            "--sc04-reference-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert "browser context binding does not match" in payload["store_results"][0]["ux_observations"]


def test_sc04_amount_or_currency_mismatch_is_a_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        write_json(
            browser,
            sc04_browser_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
                screenshots,
            ),
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        state_payload["orders"]["blocks"]["amount"] = "21.00"
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "FAIL - functional"
        assert "blocks: amount mismatch" in payload["store_results"][1]["ux_observations"]


def test_sc04_missing_saved_token_sca_surface_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        browser_payload["surfaces"].pop("sca_blocks")
        state_payload["orders"].pop("sca_blocks")
        write_json(browser, browser_payload)
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "BLOCKED"
        assert "sca_blocks browser evidence is missing" in payload["store_results"][1]["ux_observations"]


def test_sc04_incomplete_saved_token_sca_challenge_is_a_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        browser_payload["surfaces"]["sca_classic"]["sca_challenge_completed"] = False
        write_json(browser, browser_payload)
        write_json(
            state,
            sc04_state_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
            ),
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "FAIL - functional"
        assert "sca_classic: saved-token SCA challenge did not complete" in payload["store_results"][1]["ux_observations"]


def test_sc04_omitted_required_assertion_is_blocked_not_failed() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        browser_payload["surfaces"]["blocks"].pop("saved_card_selected")
        state_payload["orders"]["blocks"]["meta_presence"].pop("_charge_id")
        write_json(browser, browser_payload)
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "BLOCKED"
        assert "blocks: saved_card_selected assertion is missing" in payload["store_results"][1]["ux_observations"]
        assert "blocks: _charge_id assertion is missing" in payload["store_results"][1]["ux_observations"]


def test_sc04_non_provider_payment_method_fixture_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        write_json(
            browser,
            sc04_browser_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
                screenshots,
            ),
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        state_payload["token"]["payment_method_id"] = "not-a-provider-payment-method"
        state_payload["sca_token"]["payment_method_id"] = "also-not-a-provider-payment-method"
        for order in state_payload["orders"].values():
            order["payment_method_id"] = (
                state_payload["sca_token"]["payment_method_id"]
                if order["order_id"] in {903, 904}
                else state_payload["token"]["payment_method_id"]
            )
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "BLOCKED"
        assert "saved token provider payment method id is invalid" in payload["store_results"][1]["ux_observations"]
        assert "saved SCA token provider payment method id is invalid" in payload["store_results"][1]["ux_observations"]


def test_required_sc04_without_raw_or_prebuilt_source_writes_blocked_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"

        result = run_builder("--out-dir", str(out_dir), "--require-sc04")

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["flow"] == "SC-04-saved-card"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "browser evidence" in payload["store_results"][0]["end_state"]


def test_copies_existing_agent_result_after_contract_validation() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        source = tmp_path / "SC-04-saved-card.json"
        _, context = builder_context(out_dir)
        write_json(
            source,
            {
                "schema": "woopayments_critical_flow_result.v2",
                "flow": "SC-04-saved-card",
                "store_results": [
                    {
                        "store": "ref",
                        "verdict": "PASS",
                    },
                    {
                        "store": "target",
                        "verdict": "PASS",
                    },
                ],
                "parity_verdict": "PASS",
                "regression_note": "copied",
                "provenance": {"capture": CONTEXT_MODULE.build_capture(context, "capture-sc04")},
            },
        )

        result = run_builder("--out-dir", str(out_dir), "--copy-agent-result", str(source))

        assert result.returncode == 0, result.stderr
        assert read_json(out_dir / "SC-04-saved-card.json")["regression_note"] == "copied"


def test_legacy_agent_result_is_preserved_as_non_gating_blocked_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        source = tmp_path / "SC-04-saved-card.json"
        write_json(
            source,
            {
                "flow": "SC-04-saved-card",
                "store_results": [{"store": "ref", "verdict": "PASS"}, {"store": "target", "verdict": "PASS"}],
                "parity_verdict": "PASS",
                "regression_note": "legacy pass",
            },
        )

        result = run_builder("--out-dir", str(out_dir), "--copy-agent-result", str(source))

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["provenance_error"]["code"] == "evidence_provenance_missing"
        assert payload["schema"] == "woopayments_critical_flow_result.v2"


def test_required_agent_flow_without_source_writes_blocked_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--require-agent-flow",
            "SC-04-saved-card",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["flow"] == "SC-04-saved-card"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "Layer-A evidence for SC-04-saved-card is missing." in payload["regression_note"]


def test_required_agent_flow_does_not_overwrite_copied_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        source = tmp_path / "SC-04-saved-card.json"
        _, context = builder_context(out_dir)
        write_json(
            source,
            {
                "schema": "woopayments_critical_flow_result.v2",
                "flow": "SC-04-saved-card",
                "store_results": [{"store": "ref", "verdict": "PASS"}, {"store": "target", "verdict": "PASS"}],
                "parity_verdict": "PASS",
                "regression_note": "copied-pass",
                "provenance": {"capture": CONTEXT_MODULE.build_capture(context, "capture-sc04")},
            },
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--copy-agent-result",
            str(source),
            "--require-agent-flow",
            "SC-04-saved-card",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "PASS"
        assert payload["regression_note"] == "copied-pass"


def test_md01_browser_gate_builds_comparable_dual_store_pass(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    gate = write_md01_packet(tmp_path / "md01", context)
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md01-browser-gate",
            str(gate),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode == 0, completed.stdout + completed.stderr
    payload = read_json(out_dir / "MD-01-created-note-on-hold-notify.json")
    assert payload["flow"] == "MD-01-created-note-on-hold-notify"
    assert payload["oracle_mode"] == "comparable"
    assert payload["parity_verdict"] == "PASS"
    assert [result["verdict"] for result in payload["store_results"]] == ["PASS", "PASS"]
    assert all(result["evidence"] for result in payload["store_results"])
    observations = [
        observation
        for result in payload["store_results"]
        for observation in result["ux_observations"]
    ]
    assert any("status count and badge" in observation for observation in observations)
    assert all("summary" not in observation for observation in observations)


def test_md01_browser_gate_rejects_tampered_browser_packet(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    gate = write_md01_packet(tmp_path / "md01", context)
    gate_payload = read_json(gate)
    browser_path = Path(gate_payload["results"][0]["browser_path"])
    browser = read_json(browser_path)
    browser["assertions"]["created_note"] = False
    write_json(browser_path, browser)
    gate_payload["results"][0]["browser_sha256"] = "sha256:" + hashlib.sha256(browser_path.read_bytes()).hexdigest()
    gate_payload = md01_seal(
        {key: value for key, value in gate_payload.items() if key not in {"context_hmac", "payload_sha256"}},
        "42" * 32,
        b"woopayments-md01-browser-gate-context-v1\0",
    )
    write_json(gate, gate_payload)
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md01-browser-gate",
            str(gate),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode != 0
    assert not (out_dir / "MD-01-created-note-on-hold-notify.json").exists()


def test_md01_browser_gate_rejects_missing_deterministic_manifest(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    gate = write_md01_packet(tmp_path / "md01", context)
    gate_payload = read_json(gate)
    Path(gate_payload["deterministic_manifests"]["target"]["path"]).unlink()
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md01-browser-gate",
            str(gate),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode != 0
    assert not (out_dir / "MD-01-created-note-on-hold-notify.json").exists()


def test_md02_manifests_build_comparable_dual_store_pass(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    reference, target = write_md02_manifests(tmp_path / "md02", context)
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md02-reference-manifest",
            str(reference),
            "--md02-target-manifest",
            str(target),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode == 0, completed.stdout + completed.stderr
    payload = read_json(out_dir / "MD-02-save-evidence.json")
    assert payload["flow"] == "MD-02-save-evidence"
    assert payload["parity_verdict"] == "PASS"
    assert [result["verdict"] for result in payload["store_results"]] == ["PASS", "PASS"]
    assert all(result["evidence"] for result in payload["store_results"])
    assert "customer_name" not in json.dumps(payload)
    assert "auth_cookie" not in json.dumps(payload)
    assert all(
        not Path(item["path"]).is_absolute()
        for result in payload["store_results"]
        for item in result["evidence"]
    )
    reference_packet = read_json(reference.parent / "ref-md02-store-packet.json")
    assert not Path(reference_packet["source_manifest"]["path"]).is_absolute()


def test_md02_target_ux_failure_is_not_promoted_to_pass(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    reference, target = write_md02_manifests(tmp_path / "md02", context, target_ux_fail=True)
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md02-reference-manifest",
            str(reference),
            "--md02-target-manifest",
            str(target),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode == 0, completed.stdout + completed.stderr
    payload = read_json(out_dir / "MD-02-save-evidence.json")
    assert [result["verdict"] for result in payload["store_results"]] == ["PASS", "FAIL - UX"]
    assert payload["parity_verdict"] == "FAIL - UX"
    target_result = payload["store_results"][1]
    assert any("Save for later" in item for item in target_result["ux_observations"])
    assert any("customer" in item.lower() for item in target_result["ux_observations"])


def test_md02_blocked_result_does_not_claim_the_required_end_state(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    reference, target = write_md02_manifests(tmp_path / "md02", context, target_blocked=True)
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md02-reference-manifest",
            str(reference),
            "--md02-target-manifest",
            str(target),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode == 0, completed.stdout + completed.stderr
    payload = read_json(out_dir / "MD-02-save-evidence.json")
    target_result = payload["store_results"][1]
    assert target_result["verdict"] == "BLOCKED"
    assert "could not be established authoritatively" in target_result["end_state"]
    assert "retains an editable" not in target_result["end_state"]


def test_md02_manifest_rejects_rewritten_screenshot(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    reference, target = write_md02_manifests(tmp_path / "md02", context)
    screenshot = reference.parent / "ref-md02-form.png"
    screenshot.write_bytes(screenshot.read_bytes() + b"tampered")
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md02-reference-manifest",
            str(reference),
            "--md02-target-manifest",
            str(target),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode != 0
    assert not (out_dir / "MD-02-save-evidence.json").exists()


def test_md02_incomplete_manifest_pair_is_explicitly_blocked(tmp_path: Path) -> None:
    out_dir = tmp_path / "results"
    context_path, context = builder_context(out_dir)
    reference, _ = write_md02_manifests(tmp_path / "md02", context)
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--context-file",
            str(context_path),
            "--out-dir",
            str(out_dir),
            "--md02-reference-manifest",
            str(reference),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32},
    )
    assert completed.returncode == 0, completed.stdout + completed.stderr
    payload = read_json(out_dir / "MD-02-save-evidence.json")
    assert payload["oracle_mode"] == "comparable"
    assert payload["parity_verdict"] == "BLOCKED"
    assert [result["verdict"] for result in payload["store_results"]] == ["BLOCKED", "BLOCKED"]


def main() -> None:
    tests = [
        test_builds_plugin_active_agent_result_from_reference_and_target_gates,
        test_lpm_gate_blocked_result_stays_blocked_with_method_context,
        test_token_continuity_pass_maps_to_ss10_agent_result,
        test_ms07_browser_and_state_results_map_to_agent_pass,
        test_ms07_stale_subscription_sources_are_blocked,
        test_required_ms07_without_source_writes_blocked_result,
        test_sc04_browser_and_state_results_map_to_agent_pass,
        test_sc04_stale_store_or_source_context_is_blocked,
        test_sc04_complete_saved_card_regression_is_a_failure,
        test_sc04_context_binding_mismatch_is_blocked,
        test_sc04_amount_or_currency_mismatch_is_a_failure,
        test_sc04_missing_saved_token_sca_surface_is_blocked,
        test_sc04_incomplete_saved_token_sca_challenge_is_a_failure,
        test_sc04_omitted_required_assertion_is_blocked_not_failed,
        test_sc04_non_provider_payment_method_fixture_is_blocked,
        test_required_sc04_without_raw_or_prebuilt_source_writes_blocked_result,
        test_copies_existing_agent_result_after_contract_validation,
        test_legacy_agent_result_is_preserved_as_non_gating_blocked_evidence,
        test_required_agent_flow_without_source_writes_blocked_result,
        test_required_agent_flow_does_not_overwrite_copied_result,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
