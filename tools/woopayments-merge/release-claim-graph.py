#!/usr/bin/env python3
"""Build and validate the cell-complete WooPayments release-claim graph."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any, Iterable


REPO = Path(__file__).resolve().parents[2]
TOOLS_DIR = REPO / "tools/woopayments-merge"
DEFAULT_MATRIX = REPO / "tools/woopayments-critical-flows/matrix.tsv"
DEFAULT_WAVE_MAP = TOOLS_DIR / "release-claim-flow-wave-map.tsv"
DEFAULT_ROLE_POLICY = TOOLS_DIR / "release-claim-role-policy.tsv"
DEFAULT_PRODUCERS = TOOLS_DIR / "release-claim-producers.tsv"

SCHEMA = "woopayments_release_claim_graph.v1"
ARTIFACT_SCHEMA = "woopayments_release_claim_artifacts.v1"
EVIDENCE_SCHEMA = "woopayments_claim_evidence.v1"
CONTEXT_SCHEMA = "woopayments_claim_context.v1"
LAYER_GRAMMAR = {
    "A": ("A",),
    "D": ("D",),
    "D+A": ("D", "A"),
    "A (+D)": ("A", "D"),
    "A (+D assert)": ("A", "D_ASSERT"),
}
ALLOWED_LAYERS = frozenset({"A", "D", "D_ASSERT"})
ALLOWED_REUSE_KEYS = frozenset({"cell"})
ALLOWED_ROLES = frozenset({"golden", "target", "coexistence"})
ALLOWED_TRANSITIONS = frozenset({"coexistence->native->coexistence"})
POLICY_TARGETS = {
    "comparable": (("steady_state", "golden"), ("steady_state", "target")),
    "coexistence_comparison": (("steady_state", "golden"), ("steady_state", "coexistence")),
    "target_transition": (("transition", "coexistence->native->coexistence"),),
}
ASSERTION_STATUSES = frozenset({"PASS", "FAIL", "BLOCKED", "PENDING"})
SHA256_PATTERN = re.compile(r"^sha256:[0-9a-f]{64}$")
ASSERTION_SCHEMAS = frozenset(
    {
        "claim-assertion.v1",
        "critical-flow-agent-result.v1",
        "woopayments-deterministic-evidence.v1",
    }
)
WAIVER_CONTRACTS = {
    ("SC-11", "A", "sc11.hosted_capability"): "account_scoped_lpm_capability",
    ("ON-01", "A", "on01.live_kyc"): "live_kyc",
    ("ON-02", "A", "on02.live_kyc"): "live_kyc",
}
MIXED_ASSERTION_SUFFIXES = (
    "settings",
    "entry_point",
    "test_onboarding",
    "checkout",
    "money",
    "restoration",
)


class ClaimGraphError(RuntimeError):
    """Raised when a claim graph input or payload is structurally invalid."""


def expand_layers(token: str) -> tuple[str, ...]:
    try:
        return LAYER_GRAMMAR[token]
    except KeyError as exc:
        raise ClaimGraphError(f"unknown layer grammar: {token!r}") from exc


def file_sha256(path: Path) -> str:
    if not path.is_file():
        raise ClaimGraphError(f"input does not exist: {path}")
    return f"sha256:{hashlib.sha256(path.read_bytes()).hexdigest()}"


def canonical_json(payload: Any) -> str:
    return json.dumps(payload, ensure_ascii=False, separators=(",", ":"), sort_keys=True)


def content_digest(payload: dict[str, Any]) -> str:
    digest_payload = dict(payload)
    digest_payload.pop("graph_sha256", None)
    return f"sha256:{hashlib.sha256(canonical_json(digest_payload).encode('utf-8')).hexdigest()}"


def verify_graph_digest(graph: dict[str, Any]) -> bool:
    return graph.get("graph_sha256") == content_digest(graph)


def _read_tsv(path: Path, expected_header: tuple[str, ...]) -> list[dict[str, str]]:
    if not path.is_file():
        raise ClaimGraphError(f"TSV input does not exist: {path}")
    lines = [
        line
        for line in path.read_text(encoding="utf-8").splitlines()
        if line.strip() and not line.startswith("#")
    ]
    if not lines:
        raise ClaimGraphError(f"TSV input is empty: {path}")
    header = tuple(lines[0].split("\t"))
    if header != expected_header:
        raise ClaimGraphError(
            f"unexpected TSV header in {path}: expected {expected_header!r}, got {header!r}"
        )
    reader = csv.DictReader(lines, delimiter="\t")
    rows: list[dict[str, str]] = []
    for line_number, row in enumerate(reader, start=2):
        if None in row or any(value is None for value in row.values()):
            raise ClaimGraphError(f"malformed TSV row in {path}:{line_number}")
        cleaned = {key: value.strip() for key, value in row.items()}
        if any(not value for value in cleaned.values()):
            raise ClaimGraphError(f"empty TSV field in {path}:{line_number}")
        rows.append(cleaned)
    return rows


def _load_matrix(path: Path) -> list[dict[str, str]]:
    rows = _read_tsv(path, ("id", "title", "layers", "oracle", "status"))
    if not rows:
        raise ClaimGraphError(f"matrix contains no flows: {path}")
    seen: set[str] = set()
    for row in rows:
        flow_id = row["id"]
        if flow_id in seen:
            raise ClaimGraphError(f"duplicate matrix flow: {flow_id}")
        seen.add(flow_id)
        expand_layers(row["layers"])
        if row["oracle"] not in {"comparable", "target-only"}:
            raise ClaimGraphError(f"unknown oracle mode for {flow_id}: {row['oracle']}")
    return rows


def _load_wave_map(path: Path, flow_ids: set[str]) -> dict[str, dict[str, str]]:
    rows = _read_tsv(
        path,
        ("id", "primary_wave", "supplemental_wave", "mutation_class", "release_evidence"),
    )
    mapped: dict[str, dict[str, str]] = {}
    for row in rows:
        flow_id = row["id"]
        if flow_id in mapped:
            raise ClaimGraphError(f"duplicate wave-map flow: {flow_id}")
        if flow_id not in flow_ids:
            raise ClaimGraphError(f"unknown wave-map flow: {flow_id}")
        try:
            wave_numbers = (int(row["primary_wave"]), int(row["supplemental_wave"]))
        except ValueError as exc:
            raise ClaimGraphError(f"invalid wave assignment for {flow_id}") from exc
        if any(number < 0 for number in wave_numbers):
            raise ClaimGraphError(f"invalid wave assignment for {flow_id}")
        mapped[flow_id] = row
    missing = sorted(flow_ids - mapped.keys())
    if missing:
        raise ClaimGraphError(f"missing wave-map flows: {', '.join(missing)}")
    return mapped


def _load_role_policy(path: Path, matrix: list[dict[str, str]]) -> dict[str, list[dict[str, str]]]:
    rows = _read_tsv(path, ("flow_id", "policy_kind", "role_kind", "role_id"))
    matrix_by_id = {row["id"]: row for row in matrix}
    grouped: dict[str, list[dict[str, str]]] = defaultdict(list)
    seen_targets: set[tuple[str, str, str]] = set()
    policy_kinds: dict[str, str] = {}
    for row in rows:
        flow_id = row["flow_id"]
        if flow_id not in matrix_by_id:
            raise ClaimGraphError(f"unknown role-policy flow: {flow_id}")
        policy_kind = row["policy_kind"]
        if policy_kind not in POLICY_TARGETS:
            raise ClaimGraphError(f"unknown role policy kind for {flow_id}: {policy_kind}")
        if flow_id in policy_kinds and policy_kinds[flow_id] != policy_kind:
            raise ClaimGraphError(f"conflicting role policy kind for {flow_id}")
        policy_kinds[flow_id] = policy_kind

        role_kind = row["role_kind"]
        role_id = row["role_id"]
        if role_kind == "steady_state":
            if role_id not in ALLOWED_ROLES:
                raise ClaimGraphError(f"unknown role for {flow_id}: {role_id}")
        elif role_kind == "transition":
            if role_id not in ALLOWED_TRANSITIONS:
                raise ClaimGraphError(f"unknown transition for {flow_id}: {role_id}")
        else:
            raise ClaimGraphError(f"unknown role kind for {flow_id}: {role_kind}")

        target = (flow_id, role_kind, role_id)
        if target in seen_targets:
            raise ClaimGraphError(
                f"duplicate claim cell policy for {flow_id}:{role_kind}:{role_id}"
            )
        seen_targets.add(target)
        grouped[flow_id].append(row)

    for matrix_row in matrix:
        flow_id = matrix_row["id"]
        if flow_id not in grouped:
            raise ClaimGraphError(f"missing role policy for {flow_id}")
        policy_kind = policy_kinds[flow_id]
        actual = tuple((row["role_kind"], row["role_id"]) for row in grouped[flow_id])
        expected = POLICY_TARGETS[policy_kind]
        if actual != expected:
            if len(set(actual)) != len(actual):
                raise ClaimGraphError(f"duplicate claim cell for {flow_id}")
            raise ClaimGraphError(
                f"role policy mismatch for {flow_id}: {policy_kind} requires {expected!r}, got {actual!r}"
            )
        if matrix_row["oracle"] == "target-only" and policy_kind != "target_transition":
            raise ClaimGraphError(f"target-only flow {flow_id} requires a target transition policy")
        if matrix_row["oracle"] == "comparable" and policy_kind == "target_transition":
            raise ClaimGraphError(f"comparable flow {flow_id} cannot use a target transition policy")
    return grouped


def _resolve_owned_path(source_root: Path, value: str, label: str) -> Path:
    raw = Path(value)
    resolved = (raw if raw.is_absolute() else source_root / raw).resolve()
    try:
        resolved.relative_to(source_root.resolve())
    except ValueError as exc:
        raise ClaimGraphError(f"{label} escapes source root: {value}") from exc
    return resolved


def _parse_assertions(
    raw: str, producer_id: str, flow_id: str, layer: str
) -> list[dict[str, str]]:
    try:
        assertions = strict_json_loads(raw, f"assertions JSON for {producer_id}")
    except ClaimGraphError as exc:
        raise ClaimGraphError(f"invalid assertions JSON for {producer_id}: {exc}") from exc
    if not isinstance(assertions, list) or not assertions:
        raise ClaimGraphError(f"producer {producer_id} must declare assertions")
    normalized: list[dict[str, str]] = []
    seen: set[str] = set()
    expected_keys = {"id", "schema", "waiver_class"}
    for assertion in assertions:
        if not isinstance(assertion, dict) or set(assertion) != expected_keys:
            raise ClaimGraphError(f"invalid assertion contract for {producer_id}")
        if any(not isinstance(assertion[key], str) or not assertion[key] for key in expected_keys):
            raise ClaimGraphError(f"empty assertion contract field for {producer_id}")
        if assertion["id"] in seen:
            raise ClaimGraphError(f"duplicate assertion ID for {producer_id}: {assertion['id']}")
        if assertion["schema"] not in ASSERTION_SCHEMAS:
            raise ClaimGraphError(
                f"unsupported assertion schema for {producer_id}: {assertion['schema']}"
            )
        expected_waiver = WAIVER_CONTRACTS.get((flow_id, layer, assertion["id"]), "none")
        if assertion["waiver_class"] != expected_waiver:
            raise ClaimGraphError(
                f"unauthorized waiver contract for {producer_id}: {assertion['id']}"
            )
        seen.add(assertion["id"])
        normalized.append({key: assertion[key] for key in ("id", "schema", "waiver_class")})
    if flow_id in {"SC-11", "ON-01", "ON-02"} and layer == "A":
        stem = flow_id.lower().replace("-", "")
        required = {f"{stem}.{suffix}" for suffix in MIXED_ASSERTION_SUFFIXES}
        required.update(
            assertion_id
            for waiver_flow, waiver_layer, assertion_id in WAIVER_CONTRACTS
            if (waiver_flow, waiver_layer) == (flow_id, layer)
        )
        missing = sorted(required - seen)
        if missing:
            raise ClaimGraphError(
                f"producer {producer_id} missing required mixed assertions: {', '.join(missing)}"
            )
    return normalized


def _parse_target_bindings(
    raw: str,
    producer_id: str,
    expected_targets: list[dict[str, str]],
) -> list[dict[str, str]]:
    try:
        targets = strict_json_loads(raw, f"target bindings JSON for {producer_id}")
    except ClaimGraphError as exc:
        raise ClaimGraphError(f"invalid target bindings JSON for {producer_id}: {exc}") from exc
    expected_keys = {"role_kind", "role_id"}
    if not isinstance(targets, list) or not targets:
        raise ClaimGraphError(f"producer {producer_id} must declare target bindings")
    if any(not isinstance(target, dict) or set(target) != expected_keys for target in targets):
        raise ClaimGraphError(f"invalid target binding for {producer_id}")
    normalized = [
        {"role_kind": target["role_kind"], "role_id": target["role_id"]}
        for target in targets
    ]
    expected = [
        {"role_kind": target["role_kind"], "role_id": target["role_id"]}
        for target in expected_targets
    ]
    if normalized != expected:
        raise ClaimGraphError(f"target binding mismatch for {producer_id}")
    return normalized


def _load_producers(
    path: Path,
    matrix: list[dict[str, str]],
    role_policy: dict[str, list[dict[str, str]]],
    source_root: Path,
) -> dict[tuple[str, str], dict[str, Any]]:
    rows = _read_tsv(
        path,
        (
            "producer_id",
            "version",
            "flow_id",
            "layer",
            "producer_path",
            "producer_sha256",
            "validator_path",
            "validator_sha256",
            "target_bindings_json",
            "reuse_key",
            "assertions_json",
        ),
    )
    matrix_by_id = {row["id"]: row for row in matrix}
    producers: dict[tuple[str, str], dict[str, Any]] = {}
    seen_ids: set[str] = set()
    for row in rows:
        producer_id = row["producer_id"]
        flow_id = row["flow_id"]
        layer = row["layer"]
        if producer_id in seen_ids:
            raise ClaimGraphError(f"duplicate producer ID: {producer_id}")
        seen_ids.add(producer_id)
        if flow_id not in matrix_by_id:
            raise ClaimGraphError(f"unknown producer flow: {flow_id}")
        if layer not in ALLOWED_LAYERS or layer not in expand_layers(matrix_by_id[flow_id]["layers"]):
            raise ClaimGraphError(f"producer {producer_id} flow {flow_id} does not require layer {layer}")
        if (flow_id, layer) in producers:
            raise ClaimGraphError(f"duplicate producer for {flow_id}:{layer}")
        if row["reuse_key"] not in ALLOWED_REUSE_KEYS:
            raise ClaimGraphError(f"invalid reuse key for {producer_id}: {row['reuse_key']}")
        producer_path = _resolve_owned_path(source_root, row["producer_path"], "producer path")
        validator_path = _resolve_owned_path(source_root, row["validator_path"], "validator path")
        if not producer_path.is_file():
            raise ClaimGraphError(f"producer does not exist for {producer_id}: {producer_path}")
        if not validator_path.is_file():
            raise ClaimGraphError(f"validator does not exist for {producer_id}: {validator_path}")
        if file_sha256(producer_path) != row["producer_sha256"]:
            raise ClaimGraphError(f"stale producer digest for {producer_id}")
        if file_sha256(validator_path) != row["validator_sha256"]:
            raise ClaimGraphError(f"stale validator digest for {producer_id}")

        producers[(flow_id, layer)] = {
            "status": "BOUND",
            "producer_id": producer_id,
            "version": row["version"],
            "producer_path": row["producer_path"],
            "producer_sha256": row["producer_sha256"],
            "validator_path": row["validator_path"],
            "validator_sha256": row["validator_sha256"],
            "target_bindings": _parse_target_bindings(
                row["target_bindings_json"], producer_id, role_policy[flow_id]
            ),
            "reuse_key": row["reuse_key"],
            "assertions": _parse_assertions(
                row["assertions_json"], producer_id, flow_id, layer
            ),
        }
    return producers


def _unwired_assertion(flow_id: str, layer: str) -> list[dict[str, str]]:
    return [
        {
            "id": f"{flow_id.lower().replace('-', '')}.{layer.lower()}.required",
            "schema": "critical-flow-required-claim.v1",
            "waiver_class": "none",
        }
    ]


def build_graph(
    *,
    matrix_path: Path,
    wave_map_path: Path,
    role_policy_path: Path,
    producer_registry_path: Path,
    source_root: Path,
) -> dict[str, Any]:
    matrix_path = Path(matrix_path)
    wave_map_path = Path(wave_map_path)
    role_policy_path = Path(role_policy_path)
    producer_registry_path = Path(producer_registry_path)
    source_root = Path(source_root)

    matrix = _load_matrix(matrix_path)
    flow_ids = {row["id"] for row in matrix}
    wave_map = _load_wave_map(wave_map_path, flow_ids)
    role_policy = _load_role_policy(role_policy_path, matrix)
    producers = _load_producers(producer_registry_path, matrix, role_policy, source_root)

    flows: list[dict[str, Any]] = []
    cells: list[dict[str, Any]] = []
    missing_obligations: set[str] = set()
    missing_cells: list[str] = []
    seen_cells: set[str] = set()
    for matrix_row in matrix:
        flow_id = matrix_row["id"]
        layers = expand_layers(matrix_row["layers"])
        flow_cells: list[str] = []
        for layer in layers:
            producer = producers.get((flow_id, layer))
            for role in role_policy[flow_id]:
                cell_id = f"{flow_id}:{layer}:{role['role_kind']}:{role['role_id']}"
                if cell_id in seen_cells:
                    raise ClaimGraphError(f"duplicate claim cell: {cell_id}")
                seen_cells.add(cell_id)
                if producer is None:
                    producer_payload: dict[str, Any] = {"status": "NO_PRODUCER"}
                    assertions = _unwired_assertion(flow_id, layer)
                    status = "NO_PRODUCER"
                    missing_obligations.add(f"{flow_id}:{layer}")
                    missing_cells.append(cell_id)
                else:
                    producer_payload = {key: value for key, value in producer.items() if key != "assertions"}
                    assertions = producer["assertions"]
                    status = "PENDING"
                cell = {
                    "cell_id": cell_id,
                    "flow_id": flow_id,
                    "layer": layer,
                    "role_kind": role["role_kind"],
                    "role_id": role["role_id"],
                    "producer": producer_payload,
                    "assertions": assertions,
                    "status": status,
                }
                cells.append(cell)
                flow_cells.append(cell_id)
        wave = wave_map[flow_id]
        flows.append(
            {
                "flow_id": flow_id,
                "title": matrix_row["title"],
                "layer_grammar": matrix_row["layers"],
                "oracle": matrix_row["oracle"],
                "matrix_status": matrix_row["status"],
                "primary_wave": int(wave["primary_wave"]),
                "supplemental_wave": int(wave["supplemental_wave"]),
                "mutation_class": wave["mutation_class"],
                "release_evidence": wave["release_evidence"],
                "cell_ids": flow_cells,
            }
        )

    payload: dict[str, Any] = {
        "schema": SCHEMA,
        "inputs": {
            "matrix": {"path": str(matrix_path), "sha256": file_sha256(matrix_path)},
            "wave_map": {"path": str(wave_map_path), "sha256": file_sha256(wave_map_path)},
            "role_policy": {"path": str(role_policy_path), "sha256": file_sha256(role_policy_path)},
            "producer_registry": {
                "path": str(producer_registry_path),
                "sha256": file_sha256(producer_registry_path),
            },
        },
        "flows": flows,
        "cells": cells,
        "summary": {
            "flow_count": len(flows),
            "cell_count": len(cells),
            "bound_cell_count": len(cells) - len(missing_cells),
            "missing_producer_cell_count": len(missing_cells),
            "missing_producer_cells": missing_cells,
            "missing_producer_obligation_count": len(missing_obligations),
            "missing_producer_obligations": sorted(missing_obligations),
            "preflight_verdict": "NOT_READY" if missing_cells else "READY",
        },
    }
    payload["graph_sha256"] = content_digest(payload)
    return payload


def build_default_graph() -> dict[str, Any]:
    return build_graph(
        matrix_path=DEFAULT_MATRIX,
        wave_map_path=DEFAULT_WAVE_MAP,
        role_policy_path=DEFAULT_ROLE_POLICY,
        producer_registry_path=DEFAULT_PRODUCERS,
        source_root=REPO,
    )


def _graph_cells(graph: dict[str, Any]) -> dict[str, dict[str, Any]]:
    if not isinstance(graph, dict):
        raise ClaimGraphError("graph must be an object")
    if graph.get("schema") != SCHEMA:
        raise ClaimGraphError(f"unknown graph schema: {graph.get('schema')!r}")
    if not verify_graph_digest(graph):
        raise ClaimGraphError("graph content digest mismatch")
    cells = graph.get("cells")
    if not isinstance(cells, list):
        raise ClaimGraphError("graph cells must be a list")
    indexed: dict[str, dict[str, Any]] = {}
    for cell in cells:
        if not isinstance(cell, dict) or not isinstance(cell.get("cell_id"), str):
            raise ClaimGraphError("invalid graph cell")
        if cell["cell_id"] in indexed:
            raise ClaimGraphError(f"duplicate claim cell: {cell['cell_id']}")
        indexed[cell["cell_id"]] = cell
    return indexed


def _canonical_graph_cells(
    graph: dict[str, Any], canonical_graph: dict[str, Any]
) -> dict[str, dict[str, Any]]:
    _graph_cells(graph)
    canonical_cells = _graph_cells(canonical_graph)
    if graph != canonical_graph:
        raise ClaimGraphError("graph does not match canonical maintained inputs")
    return canonical_cells


def validate_preflight(
    graph: dict[str, Any], *, canonical_graph: dict[str, Any]
) -> dict[str, Any]:
    cells = _canonical_graph_cells(graph, canonical_graph)
    missing_cells = sorted(
        cell_id for cell_id, cell in cells.items() if cell.get("producer", {}).get("status") != "BOUND"
    )
    missing_obligations = sorted(
        {f"{cells[cell_id]['flow_id']}:{cells[cell_id]['layer']}" for cell_id in missing_cells}
    )
    return {
        "schema": "woopayments_release_claim_preflight.v1",
        "graph_sha256": graph["graph_sha256"],
        "verdict": "NOT_READY" if missing_cells else "READY",
        "missing_producer_cells": missing_cells,
        "missing_producer_obligations": missing_obligations,
    }


def _require_exact_object(value: Any, keys: set[str], label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ClaimGraphError(f"{label} must be an object")
    if set(value) != keys:
        raise ClaimGraphError(f"{label} fields are invalid")
    return value


def _require_text(value: Any, label: str) -> str:
    if not isinstance(value, str) or not value:
        raise ClaimGraphError(f"{label} must be a non-empty string")
    return value


def _require_digest(value: Any, label: str) -> str:
    digest = _require_text(value, label)
    if not SHA256_PATTERN.fullmatch(digest):
        raise ClaimGraphError(f"{label} must be a SHA-256 digest")
    return digest


def json_digest(payload: Any) -> str:
    return f"sha256:{hashlib.sha256(canonical_json(payload).encode('utf-8')).hexdigest()}"


def _context_binding(context: dict[str, Any]) -> dict[str, str]:
    return {
        "campaign_id": context["campaign_id"],
        "context_id": context["context_id"],
        "source_sha256": context["source_sha256"],
        "context_sha256": json_digest(context),
    }


def _validate_expected_context(
    context: dict[str, Any], cells: dict[str, dict[str, Any]]
) -> dict[str, Any]:
    context = _require_exact_object(
        context,
        {
            "schema",
            "campaign_id",
            "context_id",
            "source_sha256",
            "steady_states",
            "transitions",
        },
        "expected context",
    )
    if context["schema"] != CONTEXT_SCHEMA:
        raise ClaimGraphError("unknown expected context schema")
    _require_text(context["campaign_id"], "expected context campaign_id")
    _require_text(context["context_id"], "expected context context_id")
    _require_digest(context["source_sha256"], "expected context source_sha256")

    required_roles = {
        cell["role_id"] for cell in cells.values() if cell["role_kind"] == "steady_state"
    }
    steady_states = context["steady_states"]
    if not isinstance(steady_states, dict) or set(steady_states) != required_roles:
        raise ClaimGraphError("expected context steady-state roles do not match graph")
    for role_id, binding in steady_states.items():
        binding = _require_exact_object(
            binding,
            {"store_fingerprint", "account_fingerprint"},
            f"steady-state context {role_id}",
        )
        _require_text(binding["store_fingerprint"], f"steady-state {role_id} store fingerprint")
        _require_text(binding["account_fingerprint"], f"steady-state {role_id} account fingerprint")

    required_transitions = {
        cell["role_id"] for cell in cells.values() if cell["role_kind"] == "transition"
    }
    transitions = context["transitions"]
    if not isinstance(transitions, dict) or set(transitions) != required_transitions:
        raise ClaimGraphError("expected context transitions do not match graph")
    for transition_id, phases in transitions.items():
        if transition_id != "coexistence->native->coexistence":
            raise ClaimGraphError(f"unknown expected transition: {transition_id}")
        if not isinstance(phases, list) or len(phases) != 3:
            raise ClaimGraphError(f"transition {transition_id} must contain three phases")
        expected_phases = ("coexistence_before", "native", "coexistence_after")
        for expected_phase, phase in zip(expected_phases, phases):
            phase = _require_exact_object(
                phase,
                {"phase", "snapshot_id", "store_fingerprint", "account_fingerprint"},
                f"transition {transition_id} phase",
            )
            if phase["phase"] != expected_phase:
                raise ClaimGraphError(f"transition {transition_id} phase order mismatch")
            for key in ("snapshot_id", "store_fingerprint", "account_fingerprint"):
                _require_text(phase[key], f"transition {transition_id} {expected_phase} {key}")
    return context


def _expected_role_binding(cell: dict[str, Any], context: dict[str, Any]) -> dict[str, Any]:
    if cell["role_kind"] == "steady_state":
        return {
            "kind": "steady_state",
            "role_id": cell["role_id"],
            **context["steady_states"][cell["role_id"]],
        }
    return {
        "kind": "transition",
        "transition_id": cell["role_id"],
        "phases": context["transitions"][cell["role_id"]],
    }


def _resolve_artifact_path(root: Path, raw: Any, label: str) -> Path:
    value = _require_text(raw, label)
    candidate = Path(value)
    if candidate.is_absolute():
        raise ClaimGraphError(f"{label} must be relative to the artifact root")
    resolved = (root / candidate).resolve()
    try:
        resolved.relative_to(root.resolve())
    except ValueError as exc:
        raise ClaimGraphError(f"{label} escapes artifact root") from exc
    return resolved


def _read_bound_file(
    root: Path,
    raw_path: Any,
    declared_digest: Any,
    label: str,
    errors: list[str],
) -> tuple[Path, bytes, str] | None:
    path = _resolve_artifact_path(root, raw_path, f"{label} path")
    expected_digest = _require_digest(declared_digest, f"{label} digest")
    if not path.is_file():
        errors.append(f"{label} is missing: {raw_path}")
        return None
    payload = path.read_bytes()
    actual_digest = f"sha256:{hashlib.sha256(payload).hexdigest()}"
    if actual_digest != expected_digest:
        errors.append(f"{label} digest mismatch: {raw_path}")
    return path, payload, actual_digest


def _validate_cell_evidence(
    cell: dict[str, Any],
    entry: dict[str, Any],
    artifact_root: Path,
    context: dict[str, Any],
) -> tuple[list[str], list[str], list[str]]:
    cell_id = cell["cell_id"]
    errors: list[str] = []
    non_green: list[str] = []
    waiver_candidates: list[str] = []
    producer = cell["producer"]
    if producer.get("status") != "BOUND":
        return [f"{cell_id} has no producer"], non_green, waiver_candidates

    artifact_bound = _read_bound_file(
        artifact_root,
        entry["artifact_path"],
        entry["artifact_sha256"],
        f"{cell_id} artifact",
        errors,
    )
    if artifact_bound is None:
        return errors, non_green, waiver_candidates
    _, artifact_bytes, _ = artifact_bound
    artifact = strict_json_loads(artifact_bytes, f"{cell_id} artifact")
    artifact = _require_exact_object(
        artifact,
        {"schema", "artifact_id", "cell_id", "context", "role_binding", "execution", "assertions"},
        f"{cell_id} artifact",
    )
    if artifact["schema"] != EVIDENCE_SCHEMA:
        errors.append(f"{cell_id} artifact schema mismatch")
    if artifact["artifact_id"] != entry["artifact_id"]:
        errors.append(f"{cell_id} artifact ID mismatch")
    if artifact["cell_id"] != cell_id:
        errors.append(f"{cell_id} artifact cell mismatch")
    if artifact["context"] != _context_binding(context):
        errors.append(f"{cell_id} artifact context mismatch")
    expected_role = _expected_role_binding(cell, context)
    if artifact["role_binding"] != expected_role:
        errors.append(f"{cell_id} artifact role mismatch")
    expected_execution = {
        "execution_id": f"execution:{entry['artifact_id']}",
        "producer_id": producer["producer_id"],
        "producer_version": producer["version"],
        "producer_sha256": producer["producer_sha256"],
        "validator_path": producer["validator_path"],
        "validator_sha256": producer["validator_sha256"],
    }
    if artifact["execution"] != expected_execution:
        errors.append(f"{cell_id} artifact execution mismatch")

    artifact_assertions = artifact["assertions"]
    if not isinstance(artifact_assertions, list):
        raise ClaimGraphError(f"{cell_id} artifact assertions must be a list")
    artifact_by_id: dict[str, dict[str, Any]] = {}
    for assertion in artifact_assertions:
        assertion = _require_exact_object(
            assertion,
            {"id", "schema", "evidence"},
            f"{cell_id} artifact assertion",
        )
        assertion_id = _require_text(assertion["id"], f"{cell_id} assertion ID")
        if assertion_id in artifact_by_id:
            raise ClaimGraphError(f"{cell_id} duplicate artifact assertion: {assertion_id}")
        evidence = _require_exact_object(
            assertion["evidence"],
            {"assertion_id", "outcome"},
            f"{cell_id} assertion evidence",
        )
        if evidence["assertion_id"] != assertion_id:
            raise ClaimGraphError(f"{cell_id} assertion evidence ID mismatch: {assertion_id}")
        outcome = _require_text(evidence["outcome"], f"{cell_id} assertion outcome")
        if outcome not in ASSERTION_STATUSES:
            raise ClaimGraphError(f"{cell_id} assertion outcome is invalid: {assertion_id}")
        artifact_by_id[assertion_id] = assertion

    contracts = {contract["id"]: contract for contract in cell["assertions"]}
    if set(artifact_by_id) != set(contracts):
        errors.append(f"{cell_id} assertion set mismatch")
    for assertion_id, contract in contracts.items():
        if assertion_id not in artifact_by_id:
            continue
        assertion = artifact_by_id[assertion_id]
        if assertion["schema"] != contract["schema"]:
            errors.append(f"{cell_id} assertion schema mismatch: {assertion_id}")
        status = assertion["evidence"]["outcome"]
        qualified = f"{cell_id}::{assertion_id}"
        if status != "PASS":
            non_green.append(f"{qualified}:{status}")
            if status == "BLOCKED" and contract["waiver_class"] != "none":
                waiver_candidates.append(f"{qualified}:{contract['waiver_class']}")
    return errors, non_green, waiver_candidates


def validate_final(
    graph: dict[str, Any],
    artifact_packet: dict[str, Any],
    *,
    canonical_graph: dict[str, Any],
    artifact_root: Path,
    expected_context: dict[str, Any],
) -> dict[str, Any]:
    cells = _canonical_graph_cells(graph, canonical_graph)
    context = _validate_expected_context(expected_context, cells)
    artifact_root = Path(artifact_root)
    if not artifact_root.is_dir():
        raise ClaimGraphError(f"artifact root does not exist: {artifact_root}")
    packet = _require_exact_object(
        artifact_packet,
        {"schema", "graph_sha256", "context_sha256", "runner_metadata", "artifacts"},
        "artifact packet",
    )
    errors: list[str] = []
    non_green_assertions: list[str] = []
    waiver_candidates: list[str] = []
    satisfied: set[str] = set()
    if packet["schema"] != ARTIFACT_SCHEMA:
        errors.append("unknown artifact packet schema")
    if packet["graph_sha256"] != graph["graph_sha256"]:
        errors.append("artifact packet graph digest mismatch")
    if packet["context_sha256"] != json_digest(context):
        errors.append("artifact packet context digest mismatch")

    runner_metadata = _require_exact_object(
        packet["runner_metadata"], {"matrix"}, "runner metadata"
    )
    matrix_metadata = _require_exact_object(
        runner_metadata["matrix"], {"covered"}, "runner matrix metadata"
    )
    runner_covered = matrix_metadata["covered"]
    if not isinstance(runner_covered, int) or isinstance(runner_covered, bool) or runner_covered < 0:
        raise ClaimGraphError("runner matrix covered must be a non-negative integer")
    artifacts = packet["artifacts"]
    if not isinstance(artifacts, list):
        raise ClaimGraphError("artifact packet artifacts must be a list")

    seen_cells: set[str] = set()
    seen_artifact_ids: set[str] = set()
    seen_paths: set[str] = set()
    seen_evidence_digests: set[str] = set()
    for entry in artifacts:
        entry = _require_exact_object(
            entry,
            {
                "artifact_id",
                "cell_id",
                "artifact_path",
                "artifact_sha256",
            },
            "artifact packet entry",
        )
        artifact_id = _require_text(entry["artifact_id"], "artifact entry ID")
        cell_id = _require_text(entry["cell_id"], "artifact entry cell ID")
        _require_digest(entry["artifact_sha256"], f"{cell_id} artifact digest")
        if cell_id not in cells:
            errors.append(f"unknown artifact cell: {cell_id}")
            continue
        entry_errors: list[str] = []
        if cell_id in seen_cells:
            entry_errors.append(f"duplicate artifact cell: {cell_id}")
        seen_cells.add(cell_id)
        if artifact_id in seen_artifact_ids:
            entry_errors.append(f"{cell_id} cell-scoped artifact reused: {artifact_id}")
        seen_artifact_ids.add(artifact_id)
        digest_value = entry["artifact_sha256"]
        if digest_value in seen_evidence_digests:
            entry_errors.append(f"{cell_id} physical evidence digest reused: {digest_value}")
        seen_evidence_digests.add(digest_value)
        path_value = _require_text(entry["artifact_path"], f"{cell_id} artifact_path")
        if path_value in seen_paths:
            entry_errors.append(f"{cell_id} physical evidence path reused: {path_value}")
        seen_paths.add(path_value)
        evidence_errors, non_green, candidates = _validate_cell_evidence(
            cells[cell_id], entry, artifact_root, context
        )
        entry_errors.extend(evidence_errors)
        errors.extend(entry_errors)
        non_green_assertions.extend(non_green)
        waiver_candidates.extend(candidates)
        if not entry_errors and not non_green:
            satisfied.add(cell_id)

    missing_cells = sorted(set(cells) - satisfied)
    verdict = "READY" if not errors and not missing_cells and not non_green_assertions else "NOT_READY"
    return {
        "schema": "woopayments_release_claim_final_validation.v1",
        "graph_sha256": graph["graph_sha256"],
        "verdict": verdict,
        "missing_cells": missing_cells,
        "errors": errors,
        "non_green_assertions": sorted(non_green_assertions),
        "waiver_candidates": sorted(waiver_candidates),
        "runner_matrix_covered": runner_covered,
    }


def strict_json_loads(payload: bytes | str, label: str) -> Any:
    def reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ClaimGraphError(f"duplicate JSON key in {label}: {key}")
            result[key] = value
        return result

    def reject_constant(value: str) -> None:
        raise ClaimGraphError(f"non-finite JSON value in {label}: {value}")

    try:
        return json.loads(
            payload,
            object_pairs_hook=reject_duplicate_keys,
            parse_constant=reject_constant,
        )
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ClaimGraphError(f"unable to parse {label}: {exc}") from exc


def read_json(path: Path) -> dict[str, Any]:
    try:
        payload = strict_json_loads(Path(path).read_bytes(), f"JSON {path}")
    except OSError as exc:
        raise ClaimGraphError(f"unable to read JSON {path}: {exc}") from exc
    if not isinstance(payload, dict):
        raise ClaimGraphError(f"JSON payload must be an object: {path}")
    return payload


def emit(payload: dict[str, Any]) -> None:
    print(json.dumps(payload, indent=2, sort_keys=True))


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(f"{json.dumps(payload, indent=2, sort_keys=True)}\n", encoding="utf-8")


def parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    build_parser = subparsers.add_parser("build", help="Build a deterministic claim graph.")
    def add_source_arguments(command_parser: argparse.ArgumentParser) -> None:
        command_parser.add_argument("--matrix", type=Path, default=DEFAULT_MATRIX)
        command_parser.add_argument("--wave-map", type=Path, default=DEFAULT_WAVE_MAP)
        command_parser.add_argument("--role-policy", type=Path, default=DEFAULT_ROLE_POLICY)
        command_parser.add_argument("--producers", type=Path, default=DEFAULT_PRODUCERS)
        command_parser.add_argument("--source-root", type=Path, default=REPO)

    add_source_arguments(build_parser)
    build_parser.add_argument("--output", type=Path, required=True)

    preflight_parser = subparsers.add_parser(
        "validate-preflight", help="Report missing producers before campaign mutation."
    )
    preflight_parser.add_argument("--graph", type=Path, required=True)
    add_source_arguments(preflight_parser)

    final_parser = subparsers.add_parser(
        "validate-final", help="Validate current artifacts against every required cell."
    )
    final_parser.add_argument("--graph", type=Path, required=True)
    final_parser.add_argument("--artifacts", type=Path, required=True)
    final_parser.add_argument("--artifact-root", type=Path, required=True)
    final_parser.add_argument("--expected-context", type=Path, required=True)
    add_source_arguments(final_parser)
    return parser.parse_args(list(argv) if argv is not None else None)


def main(argv: Iterable[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        if args.command == "build":
            graph = build_graph(
                matrix_path=args.matrix,
                wave_map_path=args.wave_map,
                role_policy_path=args.role_policy,
                producer_registry_path=args.producers,
                source_root=args.source_root,
            )
            write_json(args.output, graph)
            emit(graph)
            return 0
        graph = read_json(args.graph)
        canonical_graph = build_graph(
            matrix_path=args.matrix,
            wave_map_path=args.wave_map,
            role_policy_path=args.role_policy,
            producer_registry_path=args.producers,
            source_root=args.source_root,
        )
        if args.command == "validate-preflight":
            report = validate_preflight(graph, canonical_graph=canonical_graph)
        else:
            report = validate_final(
                graph,
                read_json(args.artifacts),
                canonical_graph=canonical_graph,
                artifact_root=args.artifact_root,
                expected_context=read_json(args.expected_context),
            )
        emit(report)
        return 0 if report["verdict"] in {"READY", "READY_WITH_WAIVERS"} else 3
    except ClaimGraphError as exc:
        emit({"schema": "woopayments_release_claim_error.v1", "verdict": "INVALID", "errors": [str(exc)]})
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
