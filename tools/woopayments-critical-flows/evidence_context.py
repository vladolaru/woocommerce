#!/usr/bin/env python3
"""Shared provenance contract for WooPayments critical-flow evidence."""

from __future__ import annotations

import argparse
import copy
import hashlib
import json
import os
import shlex
import subprocess
import sys
import uuid
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

MERGE_TOOLS_DIR = Path(__file__).resolve().parents[1] / "woopayments-merge"
if str(MERGE_TOOLS_DIR) not in sys.path:
    sys.path.insert(0, str(MERGE_TOOLS_DIR))

from local_runner_safety import LocalRunnerError  # noqa: E402
from local_runner_safety import validate_local_wp_command as validate_shared_local_wp_command  # noqa: E402


CONTEXT_SCHEMA = "woopayments_critical_flow_context.v1"
RESULT_SCHEMA = "woopayments_critical_flow_result.v2"
LOCAL_HOSTS = {"localhost", "127.0.0.1", "store8889.localhost", "woopay.localhost"}
STORE_PROBE_PHP = r"""
$active_plugins = (array) get_option( 'active_plugins', array() );
if ( is_multisite() ) {
	$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
}
$plugin_active = in_array( 'woocommerce-payments/woocommerce-payments.php', $active_plugins, true );
$runtime_owner = $plugin_active ? 'plugin' : 'none';
$failures      = array();

if ( class_exists( '\Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter' ) && function_exists( 'wc_get_container' ) ) {
	try {
		$arbiter       = wc_get_container()->get( '\Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter' );
		$runtime_owner = (string) $arbiter->get_runtime_owner();
	} catch ( Throwable $e ) {
		$failures[] = 'runtime_owner_probe_failed:' . get_class( $e );
	}
}

$cache   = get_option( 'wcpay_account_data', array() );
$account = is_array( $cache ) && isset( $cache['data'] ) && is_array( $cache['data'] ) ? $cache['data'] : $cache;
$account = is_array( $account ) ? $account : array();
$account_id = (string) ( $account['account_id'] ?? $account['id'] ?? '' );
$country    = strtoupper( (string) ( $account['country'] ?? $account['country_code'] ?? '' ) );
$capabilities = isset( $account['capabilities'] ) && is_array( $account['capabilities'] ) ? $account['capabilities'] : array();
$normalized_capabilities = array();
foreach ( $capabilities as $capability => $status ) {
	if ( is_scalar( $status ) || null === $status ) {
		$normalized_capabilities[ sanitize_key( (string) $capability ) ] = strtolower( (string) $status );
	}
}
ksort( $normalized_capabilities );

$jetpack_blog_id = class_exists( '\Jetpack_Options' ) ? (string) \Jetpack_Options::get_option( 'id' ) : '';
if ( '' === $account_id ) {
	$failures[] = 'account_id_missing';
}
if ( '' === $country ) {
	$failures[] = 'account_country_missing';
}
if ( '' === $jetpack_blog_id ) {
	$failures[] = 'jetpack_blog_id_missing';
}

WP_CLI::line(
	wp_json_encode(
		array(
			'ready'                   => empty( $failures ),
			'blog_id'                 => (int) get_current_blog_id(),
			'home_url'                => home_url(),
			'jetpack_blog_id_sha256'  => '' === $jetpack_blog_id ? '' : 'sha256:' . hash( 'sha256', $jetpack_blog_id ),
			'runtime_owner'           => $runtime_owner,
			'account'                 => array(
				'account_id_sha256' => '' === $account_id ? '' : 'sha256:' . hash( 'sha256', $account_id ),
				'country'          => $country,
				'test_mode'        => (bool) ( $account['test_mode'] ?? $account['is_test_mode'] ?? true ),
				'business_type'    => sanitize_key( (string) ( $account['business_type'] ?? '' ) ),
				'capabilities'     => $normalized_capabilities,
			),
			'failures'                 => $failures,
		)
	)
);
"""


class EvidenceContextError(ValueError):
    """Raised when evidence cannot be bound to the active aggregate context."""

    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code


def canonical_json(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")


def sha256_bytes(value: bytes) -> str:
    return f"sha256:{hashlib.sha256(value).hexdigest()}"


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def context_digest(context: dict[str, Any]) -> str:
    payload = copy.deepcopy(context)
    payload.pop("context_sha256", None)
    return sha256_bytes(canonical_json(payload))


def build_context(
    *,
    aggregate_run_id: str,
    source: dict[str, Any],
    stores: dict[str, Any],
    fixtures: dict[str, Any],
) -> dict[str, Any]:
    context = {
        "schema": CONTEXT_SCHEMA,
        "aggregate_run_id": str(aggregate_run_id),
        "source": copy.deepcopy(source),
        "stores": copy.deepcopy(stores),
        "fixtures": copy.deepcopy(fixtures),
    }
    context["context_sha256"] = context_digest(context)
    validate_context(context)
    return context


def validate_context(context: dict[str, Any]) -> None:
    if context.get("schema") != CONTEXT_SCHEMA:
        raise EvidenceContextError("evidence_context_schema_invalid", "critical-flow context schema is invalid")
    if not context.get("aggregate_run_id"):
        raise EvidenceContextError("evidence_context_run_missing", "critical-flow aggregate run id is missing")
    if set(context.get("stores", {})) != {"ref", "target"}:
        raise EvidenceContextError("evidence_context_stores_invalid", "critical-flow context must contain ref and target stores")
    if set(context.get("fixtures", {})) != {"ref", "target"}:
        raise EvidenceContextError("evidence_context_fixtures_invalid", "critical-flow context must contain ref and target fixtures")
    for role in ("ref", "target"):
        fixture = context["fixtures"].get(role)
        if not isinstance(fixture, dict) or not str(fixture.get("subscription_id") or ""):
            raise EvidenceContextError(
                "evidence_context_fixture_missing",
                f"critical-flow context is missing the {role} subscription fixture id",
            )
    if context.get("context_sha256") != context_digest(context):
        raise EvidenceContextError("evidence_context_digest_mismatch", "critical-flow context digest does not match its contents")


def capture_context(context: dict[str, Any]) -> dict[str, Any]:
    validate_context(context)
    return {
        "source": copy.deepcopy(context["source"]),
        "stores": copy.deepcopy(context["stores"]),
        "fixtures": copy.deepcopy(context["fixtures"]),
    }


def build_capture(context: dict[str, Any], capture_id: str) -> dict[str, Any]:
    return {"capture_id": str(capture_id), **capture_context(context)}


def validate_capture(capture: Any, context: dict[str, Any]) -> None:
    if not isinstance(capture, dict) or not capture.get("capture_id"):
        raise EvidenceContextError("evidence_provenance_missing", "critical-flow result provenance capture is missing")
    expected = capture_context(context)
    actual = {key: capture.get(key) for key in expected}
    if actual != expected:
        raise EvidenceContextError(
            "evidence_capture_context_mismatch",
            "critical-flow capture context does not match the active source, stores, and fixtures",
        )


def normalize_evidence_paths(
    payload: dict[str, Any],
    *,
    evidence_base_dir: Path | None = None,
) -> dict[str, Any]:
    normalized = copy.deepcopy(payload)
    for store_result in normalized.get("store_results", []):
        if not isinstance(store_result, dict):
            continue
        paths = store_result.pop("evidence_paths", [])
        if not isinstance(paths, list):
            raise EvidenceContextError("evidence_artifact_list_invalid", "critical-flow evidence paths must be a list")
        evidence = []
        for value in paths:
            path = Path(str(value))
            if not path.is_file():
                raise EvidenceContextError("evidence_artifact_missing", f"critical-flow evidence artifact is missing: {path}")
            recorded_path = (
                Path(os.path.relpath(path, evidence_base_dir)).as_posix()
                if evidence_base_dir is not None
                else str(path)
            )
            evidence.append({"path": recorded_path, "sha256": sha256_file(path)})
        if evidence:
            store_result["evidence"] = evidence
        elif "evidence" not in store_result:
            store_result["evidence"] = []
    return normalized


def _content_digest(payload: dict[str, Any]) -> str:
    content = copy.deepcopy(payload)
    provenance = content.get("provenance")
    if isinstance(provenance, dict):
        provenance.pop("import", None)
    return sha256_bytes(canonical_json(content))


def _stamp_import(
    payload: dict[str, Any],
    context: dict[str, Any],
    source_artifact_path: Path | None,
) -> dict[str, Any]:
    stamped = copy.deepcopy(payload)
    provenance = stamped.setdefault("provenance", {})
    provenance.pop("import", None)
    import_record: dict[str, Any] = {
        "aggregate_run_id": context["aggregate_run_id"],
        "context_sha256": context["context_sha256"],
        "content_sha256": _content_digest(stamped),
    }
    if source_artifact_path is not None:
        if not source_artifact_path.is_file():
            raise EvidenceContextError(
                "evidence_source_artifact_missing",
                f"critical-flow source artifact is missing: {source_artifact_path}",
            )
        import_record["source_artifact"] = {
            "path": str(source_artifact_path),
            "sha256": sha256_file(source_artifact_path),
        }
    provenance["import"] = import_record
    return stamped


def stamp_generated_result(
    payload: dict[str, Any],
    context: dict[str, Any],
    *,
    evidence_base_dir: Path | None = None,
) -> dict[str, Any]:
    validate_context(context)
    stamped = normalize_evidence_paths(payload, evidence_base_dir=evidence_base_dir)
    stamped["schema"] = RESULT_SCHEMA
    stamped["provenance"] = {"capture": build_capture(context, context["aggregate_run_id"])}
    return _stamp_import(stamped, context, None)


def import_captured_result(
    payload: dict[str, Any],
    context: dict[str, Any],
    source_artifact_path: Path,
) -> dict[str, Any]:
    validate_context(context)
    if payload.get("schema") != RESULT_SCHEMA:
        raise EvidenceContextError("evidence_provenance_missing", "legacy critical-flow result has no v2 provenance")
    provenance = payload.get("provenance")
    if not isinstance(provenance, dict):
        raise EvidenceContextError("evidence_provenance_missing", "critical-flow result provenance is missing")
    validate_capture(provenance.get("capture"), context)
    normalized = normalize_evidence_paths(payload)
    return _stamp_import(normalized, context, source_artifact_path)


def validate_imported_result(
    payload: dict[str, Any],
    context: dict[str, Any],
    expected_flow: str,
    expected_store: str | None = None,
    *,
    result_path: Path | None = None,
) -> None:
    validate_context(context)
    if payload.get("schema") != RESULT_SCHEMA:
        raise EvidenceContextError("evidence_result_schema_invalid", "critical-flow result schema is invalid")
    if payload.get("flow") != expected_flow:
        raise EvidenceContextError("evidence_flow_mismatch", "critical-flow result flow does not match the requested flow")

    provenance = payload.get("provenance")
    if not isinstance(provenance, dict):
        raise EvidenceContextError("evidence_provenance_missing", "critical-flow result provenance is missing")
    validate_capture(provenance.get("capture"), context)
    import_record = provenance.get("import")
    if not isinstance(import_record, dict):
        raise EvidenceContextError("evidence_import_missing", "critical-flow result import provenance is missing")
    if import_record.get("aggregate_run_id") != context["aggregate_run_id"]:
        raise EvidenceContextError("evidence_run_mismatch", "critical-flow result belongs to another aggregate run")
    if import_record.get("context_sha256") != context["context_sha256"]:
        raise EvidenceContextError("evidence_context_mismatch", "critical-flow result context digest does not match")
    if import_record.get("content_sha256") != _content_digest(payload):
        raise EvidenceContextError("evidence_content_mismatch", "critical-flow result content digest does not match")

    source_artifact = import_record.get("source_artifact")
    if source_artifact is not None:
        source_path = Path(str(source_artifact.get("path", "")))
        if not source_path.is_file() or source_artifact.get("sha256") != sha256_file(source_path):
            raise EvidenceContextError("evidence_source_artifact_mismatch", "critical-flow source artifact hash does not match")

    store_results = payload.get("store_results")
    if not isinstance(store_results, list):
        raise EvidenceContextError("evidence_store_results_invalid", "critical-flow result store_results must be a list")
    stores = {result.get("store") for result in store_results if isinstance(result, dict)}
    if stores != set(context["stores"]):
        raise EvidenceContextError("evidence_store_set_mismatch", "critical-flow result store set does not match the context")
    if expected_store is not None and expected_store not in stores:
        raise EvidenceContextError("evidence_store_missing", "critical-flow result does not contain the requested store")

    for store_result in store_results:
        if not isinstance(store_result, dict):
            raise EvidenceContextError("evidence_store_result_invalid", "critical-flow store result is invalid")
        evidence = store_result.get("evidence", [])
        if not isinstance(evidence, list):
            raise EvidenceContextError("evidence_artifact_list_invalid", "critical-flow evidence entries must be a list")
        for artifact in evidence:
            if not isinstance(artifact, dict):
                raise EvidenceContextError("evidence_artifact_invalid", "critical-flow evidence artifact entry is invalid")
            path = Path(str(artifact.get("path", "")))
            if not path.is_absolute() and result_path is not None:
                path = Path(os.path.abspath(result_path.parent / path))
            if not path.is_file() or artifact.get("sha256") != sha256_file(path):
                raise EvidenceContextError("evidence_artifact_mismatch", f"critical-flow evidence artifact hash does not match: {path}")


def _git(repo: Path, *args: str, text: bool = False) -> str | bytes:
    completed = subprocess.run(
        ["git", *args],
        cwd=repo,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=text,
        check=False,
    )
    if completed.returncode != 0:
        stderr = completed.stderr.strip() if text else completed.stderr.decode("utf-8", errors="replace").strip()
        raise EvidenceContextError("evidence_source_snapshot_failed", f"git {' '.join(args)} failed: {stderr}")
    return completed.stdout


def source_snapshot(repo: Path) -> dict[str, str]:
    repo = repo.resolve()
    head_sha = str(_git(repo, "rev-parse", "HEAD", text=True)).strip()
    tracked_diff = bytes(_git(repo, "diff", "--binary", "HEAD"))
    untracked_raw = bytes(_git(repo, "ls-files", "--others", "--exclude-standard", "-z"))
    untracked = []
    for raw_path in sorted(path for path in untracked_raw.split(b"\0") if path):
        relative_path = raw_path.decode("utf-8", errors="surrogateescape")
        path = repo / relative_path
        if path.is_file():
            untracked.append({"path": relative_path, "sha256": sha256_file(path)})
    worktree = {
        "tracked_diff_sha256": sha256_bytes(tracked_diff),
        "untracked": untracked,
    }
    return {
        "head_sha": head_sha,
        "worktree_sha256": sha256_bytes(canonical_json(worktree)),
    }


def validate_local_wp_command(command: str, role: str) -> list[str]:
    try:
        return validate_shared_local_wp_command(role, command)
    except LocalRunnerError as exc:
        raise EvidenceContextError("evidence_store_command_invalid", f"{role} must use local Docker: {exc}") from exc


def normalize_store_probe(probe: dict[str, Any], role: str, expected_owner: str) -> dict[str, str]:
    failures = probe.get("failures")
    if probe.get("ready") is not True or (isinstance(failures, list) and failures):
        raise EvidenceContextError("evidence_store_probe_unready", f"{role} store provenance probe is not ready: {failures}")
    runtime_owner = str(probe.get("runtime_owner") or "")
    if runtime_owner != expected_owner:
        raise EvidenceContextError(
            "evidence_store_owner_mismatch",
            f"{role} runtime owner is {runtime_owner or '<missing>'}, expected {expected_owner}",
        )
    account = probe.get("account")
    if not isinstance(account, dict) or not account.get("account_id_sha256"):
        raise EvidenceContextError("evidence_account_state_missing", f"{role} WooPayments account state is missing")
    store_identity = {
        "blog_id": int(probe.get("blog_id") or 0),
        "home_url": str(probe.get("home_url") or ""),
        "jetpack_blog_id_sha256": str(probe.get("jetpack_blog_id_sha256") or ""),
    }
    parsed_home = urlparse(store_identity["home_url"])
    if (
        store_identity["blog_id"] <= 0
        or parsed_home.hostname not in LOCAL_HOSTS
        or not store_identity["jetpack_blog_id_sha256"].startswith("sha256:")
    ):
        raise EvidenceContextError("evidence_store_identity_missing", f"{role} local store or Jetpack identity is incomplete")
    account_state = {
        "account_id_sha256": str(account.get("account_id_sha256")),
        "country": str(account.get("country") or ""),
        "test_mode": bool(account.get("test_mode")),
        "business_type": str(account.get("business_type") or ""),
        "capabilities": account.get("capabilities") if isinstance(account.get("capabilities"), dict) else {},
    }
    return {
        "store_fingerprint": sha256_bytes(canonical_json(store_identity)),
        "runtime_owner": runtime_owner,
        "account_state_sha256": sha256_bytes(canonical_json(account_state)),
    }


def parse_last_json(output: str) -> dict[str, Any]:
    decoder = json.JSONDecoder()
    candidates: list[dict[str, Any]] = []
    for index, character in enumerate(output):
        if character != "{":
            continue
        try:
            value, _ = decoder.raw_decode(output[index:])
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            candidates.append(value)
    if not candidates:
        raise EvidenceContextError("evidence_store_probe_invalid", "store provenance probe did not emit JSON")
    for candidate in reversed(candidates):
        if "ready" in candidate and "runtime_owner" in candidate:
            return candidate
    return candidates[-1]


def capture_store_state(command: str, role: str, expected_owner: str) -> dict[str, str]:
    parts = validate_local_wp_command(command, role)
    try:
        completed = subprocess.run(
            parts + ["eval", STORE_PROBE_PHP],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=120,
        )
    except subprocess.TimeoutExpired as exc:
        raise EvidenceContextError("evidence_store_probe_timeout", f"{role} store provenance probe timed out") from exc
    if completed.returncode != 0:
        detail = (completed.stderr or completed.stdout).strip()
        raise EvidenceContextError("evidence_store_probe_failed", f"{role} store provenance probe failed: {detail}")
    return normalize_store_probe(parse_last_json(completed.stdout), role, expected_owner)


def create_context_file(args: argparse.Namespace) -> dict[str, Any]:
    out = Path(args.out)
    if out.exists():
        raise EvidenceContextError("evidence_context_exists", f"critical-flow context already exists: {out}")
    context = build_context(
        aggregate_run_id=str(uuid.uuid4()),
        source=source_snapshot(Path(args.repo)),
        stores={
            "ref": capture_store_state(args.ref_wp, "ref", "plugin"),
            "target": capture_store_state(args.target_wp, "target", "native"),
        },
        fixtures={
            "ref": {"subscription_id": str(args.ref_subscription_id)},
            "target": {"subscription_id": str(args.target_subscription_id)},
        },
    )
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("x", encoding="utf-8") as stream:
        stream.write(json.dumps(context, indent=2, sort_keys=True) + "\n")
    return context


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    create = subparsers.add_parser("create", help="Capture an immutable aggregate evidence context.")
    create.add_argument("--repo", required=True)
    create.add_argument("--out", required=True)
    create.add_argument("--ref-wp", required=True)
    create.add_argument("--target-wp", required=True)
    create.add_argument("--ref-subscription-id", required=True)
    create.add_argument("--target-subscription-id", required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.command == "create":
        context = create_context_file(args)
        print(json.dumps({"status": "pass", "context_sha256": context["context_sha256"], "out": args.out}, sort_keys=True))
        return 0
    return 2


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (EvidenceContextError, OSError) as exc:
        code = exc.code if isinstance(exc, EvidenceContextError) else "evidence_context_write_failed"
        print(f"{code}: {exc}", file=sys.stderr)
        raise SystemExit(1)
