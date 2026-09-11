#!/usr/bin/env python3
"""Behavioral tests for the cell-complete WooPayments release-claim graph."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import subprocess
import sys
from pathlib import Path

import pytest


REPO = Path(__file__).resolve().parents[2]
TOOL = REPO / "tools/woopayments-merge/release-claim-graph.py"
SELF_TESTS = REPO / "tools/woopayments-merge/run-self-tests.sh"


@pytest.fixture(scope="module")
def claim_graph_module():
    assert TOOL.exists(), "release-claim-graph.py must exist"
    spec = importlib.util.spec_from_file_location("release_claim_graph", TOOL)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def sha256(path: Path) -> str:
    return f"sha256:{hashlib.sha256(path.read_bytes()).hexdigest()}"


def write_tsv(path: Path, header: list[str], rows: list[list[str]]) -> Path:
    path.write_text(
        "\t".join(header)
        + "\n"
        + "".join("\t".join(row) + "\n" for row in rows),
        encoding="utf-8",
    )
    return path


def assertion_contracts(*assertions: tuple[str, str]) -> str:
    return json.dumps(
        [
            {"id": assertion_id, "schema": "claim-assertion.v1", "waiver_class": waiver_class}
            for assertion_id, waiver_class in assertions
        ],
        separators=(",", ":"),
        sort_keys=True,
    )


def mixed_assertion_contracts(flow_id: str) -> str:
    stem = flow_id.lower().replace("-", "")
    assertions = [(f"{stem}.{suffix}", "none") for suffix in (
        "settings",
        "entry_point",
        "test_onboarding",
        "checkout",
        "money",
        "restoration",
    )]
    if flow_id == "SC-11":
        assertions.append(("sc11.hosted_capability", "account_scoped_lpm_capability"))
    else:
        assertions.append((f"{stem}.live_kyc", "live_kyc"))
    return assertion_contracts(*assertions)


def make_producer(
    root: Path,
    *,
    producer_id: str,
    flow_id: str,
    layer: str,
    assertions: str | None = None,
    reuse_key: str = "cell",
    producer_digest: str | None = None,
    validator_digest: str | None = None,
    create_validator: bool = True,
    target_bindings: list[dict[str, str]] | None = None,
) -> list[str]:
    producer_path = root / f"{producer_id}.producer"
    validator_path = root / f"{producer_id}.validator"
    producer_path.write_text(f"producer:{producer_id}\n", encoding="utf-8")
    if create_validator:
        validator_path.write_text(f"validator:{producer_id}\n", encoding="utf-8")

    if target_bindings is None:
        if flow_id in {"MA-11", "ON-04"}:
            target_bindings = [
                {"role_kind": "steady_state", "role_id": "golden"},
                {"role_kind": "steady_state", "role_id": "coexistence"},
            ]
        elif flow_id == "SS-10":
            target_bindings = [
                {
                    "role_kind": "transition",
                    "role_id": "coexistence->native->coexistence",
                }
            ]
        else:
            target_bindings = [
                {"role_kind": "steady_state", "role_id": "golden"},
                {"role_kind": "steady_state", "role_id": "target"},
            ]

    return [
        producer_id,
        "1",
        flow_id,
        layer,
        producer_path.name,
        producer_digest or sha256(producer_path),
        validator_path.name,
        validator_digest or (sha256(validator_path) if create_validator else "sha256:" + "0" * 64),
        json.dumps(target_bindings, separators=(",", ":"), sort_keys=True),
        reuse_key,
        assertions or assertion_contracts((f"{flow_id.lower()}.{layer.lower()}.outcome", "none")),
    ]


def write_inputs(
    root: Path,
    *,
    flows: list[tuple[str, str, str]],
    roles: list[tuple[str, str, str]],
    producers: list[list[str]],
) -> dict[str, Path]:
    policy_kinds: dict[str, str] = {}
    for flow_id, role_kind, role_id in roles:
        if role_kind == "transition":
            policy_kinds[flow_id] = "target_transition"
        elif role_id == "coexistence" and policy_kinds.get(flow_id) != "target_transition":
            policy_kinds[flow_id] = "coexistence_comparison"
        else:
            policy_kinds.setdefault(flow_id, "comparable")

    matrix = write_tsv(
        root / "matrix.tsv",
        ["id", "title", "layers", "oracle", "status"],
        [[flow_id, f"Flow {flow_id}", layers, oracle, "PENDING"] for flow_id, layers, oracle in flows],
    )
    wave_map = write_tsv(
        root / "wave-map.tsv",
        ["id", "primary_wave", "supplemental_wave", "mutation_class", "release_evidence"],
        [[flow_id, "2", "0", "local-reversible", f"Evidence {flow_id}"] for flow_id, _, _ in flows],
    )
    role_policy = write_tsv(
        root / "roles.tsv",
        ["flow_id", "policy_kind", "role_kind", "role_id"],
        [[flow_id, policy_kinds[flow_id], role_kind, role_id] for flow_id, role_kind, role_id in roles],
    )
    producer_registry = write_tsv(
        root / "producers.tsv",
        [
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
        ],
        producers,
    )
    return {
        "matrix": matrix,
        "wave_map": wave_map,
        "role_policy": role_policy,
        "producers": producer_registry,
    }


def build_graph(module, root: Path, paths: dict[str, Path]) -> dict:
    return module.build_graph(
        matrix_path=paths["matrix"],
        wave_map_path=paths["wave_map"],
        role_policy_path=paths["role_policy"],
        producer_registry_path=paths["producers"],
        source_root=root,
    )


def validate_preflight(module, graph: dict, *, canonical_graph: dict | None = None) -> dict:
    return module.validate_preflight(graph, canonical_graph=canonical_graph or graph)


def validate_final(
    module,
    graph: dict,
    packet: dict,
    *,
    canonical_graph: dict | None = None,
    artifact_root: Path,
    expected_context: dict,
) -> dict:
    return module.validate_final(
        graph,
        packet,
        canonical_graph=canonical_graph or graph,
        artifact_root=artifact_root,
        expected_context=expected_context,
    )


def evidence_context(graph: dict) -> dict:
    steady_roles = sorted(
        {cell["role_id"] for cell in graph["cells"] if cell["role_kind"] == "steady_state"}
    )
    transition_ids = sorted(
        {cell["role_id"] for cell in graph["cells"] if cell["role_kind"] == "transition"}
    )
    transitions = {}
    for transition_id in transition_ids:
        assert transition_id == "coexistence->native->coexistence"
        transitions[transition_id] = [
            {
                "phase": phase,
                "snapshot_id": f"snapshot:{phase}",
                "store_fingerprint": f"store:{phase}",
                "account_fingerprint": f"account:{phase}",
            }
            for phase in ("coexistence_before", "native", "coexistence_after")
        ]
    return {
        "schema": "woopayments_claim_context.v1",
        "campaign_id": "campaign:test",
        "context_id": "context:current",
        "source_sha256": "sha256:" + hashlib.sha256(b"current source").hexdigest(),
        "steady_states": {
            role: {
                "store_fingerprint": f"store:{role}",
                "account_fingerprint": f"account:{role}",
            }
            for role in steady_roles
        },
        "transitions": transitions,
    }


def context_binding(module, context: dict) -> dict:
    return {
        "campaign_id": context["campaign_id"],
        "context_id": context["context_id"],
        "source_sha256": context["source_sha256"],
        "context_sha256": module.json_digest(context),
    }


def role_binding(cell: dict, context: dict) -> dict:
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


def artifact_for(
    module,
    root: Path,
    graph: dict,
    cell: dict,
    context: dict,
    *,
    assertion_statuses: dict[str, str] | None = None,
    role_binding_override: dict | None = None,
    execution_overrides: dict[str, object] | None = None,
    omit_assertion_ids: set[str] | None = None,
    assertion_schema_overrides: dict[str, str] | None = None,
    assertion_evidence_extras: dict[str, dict[str, object]] | None = None,
    entry_overrides: dict[str, object] | None = None,
) -> dict:
    producer = cell["producer"]
    artifact_id = f"artifact:{cell['cell_id']}"
    artifact_assertions = []
    for contract in cell["assertions"]:
        assertion_id = contract["id"]
        if omit_assertion_ids and assertion_id in omit_assertion_ids:
            continue
        schema = (assertion_schema_overrides or {}).get(assertion_id, contract["schema"])
        evidence = {
            "assertion_id": assertion_id,
            "outcome": (assertion_statuses or {}).get(assertion_id, "PASS"),
        }
        evidence.update((assertion_evidence_extras or {}).get(assertion_id, {}))
        artifact_assertions.append({"id": assertion_id, "schema": schema, "evidence": evidence})

    expected_role_binding = role_binding_override or role_binding(cell, context)
    execution = {
        "execution_id": f"execution:{artifact_id}",
        "producer_id": producer["producer_id"],
        "producer_version": producer["version"],
        "producer_sha256": producer["producer_sha256"],
        "validator_path": producer["validator_path"],
        "validator_sha256": producer["validator_sha256"],
    }
    execution.update(execution_overrides or {})
    artifact = {
        "schema": "woopayments_claim_evidence.v1",
        "artifact_id": artifact_id,
        "cell_id": cell["cell_id"],
        "context": context_binding(module, context),
        "role_binding": expected_role_binding,
        "execution": execution,
        "assertions": artifact_assertions,
    }
    stem = hashlib.sha256(cell["cell_id"].encode()).hexdigest()[:16]
    evidence_dir = root / "evidence"
    evidence_dir.mkdir(exist_ok=True)
    artifact_path = evidence_dir / f"{stem}-artifact.json"
    artifact_path.write_text(json.dumps(artifact, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    artifact_digest = sha256(artifact_path)
    entry = {
        "artifact_id": artifact_id,
        "cell_id": cell["cell_id"],
        "artifact_path": str(artifact_path.relative_to(root)),
        "artifact_sha256": artifact_digest,
    }
    entry.update(entry_overrides or {})
    return entry


def artifact_packet(module, graph: dict, context: dict, artifacts: list[dict], *, covered: int = 0) -> dict:
    return {
        "schema": "woopayments_release_claim_artifacts.v1",
        "graph_sha256": graph["graph_sha256"],
        "context_sha256": module.json_digest(context),
        "runner_metadata": {"matrix": {"covered": covered}},
        "artifacts": artifacts,
    }


def validate_artifacts(
    module,
    graph: dict,
    root: Path,
    context: dict,
    artifacts: list[dict],
    *,
    covered: int = 0,
    canonical_graph: dict | None = None,
) -> dict:
    return validate_final(
        module,
        graph,
        artifact_packet(module, graph, context, artifacts, covered=covered),
        canonical_graph=canonical_graph,
        artifact_root=root,
        expected_context=context,
    )


@pytest.mark.parametrize(
    "token,expected",
    [
        pytest.param("A", ("A",), id="agent"),
        pytest.param("D", ("D",), id="deterministic"),
        pytest.param("D+A", ("D", "A"), id="deterministic-and-agent"),
        pytest.param("A (+D)", ("A", "D"), id="agent-with-deterministic"),
        pytest.param("A (+D assert)", ("A", "D_ASSERT"), id="agent-with-distinct-deterministic-assertion"),
    ],
)
def test_complete_layer_grammar_expands_required_cells(
    claim_graph_module, token: str, expected: tuple[str, ...]
) -> None:
    assert claim_graph_module.expand_layers(token) == expected


def test_unknown_layer_grammar_is_rejected(claim_graph_module) -> None:
    with pytest.raises(claim_graph_module.ClaimGraphError, match="unknown layer grammar"):
        claim_graph_module.expand_layers("A+D maybe")


def test_build_is_deterministic_and_content_addressed(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )

    first = build_graph(claim_graph_module, tmp_path, paths)
    second = build_graph(claim_graph_module, tmp_path, paths)

    assert first == second
    assert first["graph_sha256"].startswith("sha256:")
    assert claim_graph_module.verify_graph_digest(first) is True
    assert [cell["role_id"] for cell in first["cells"]] == ["golden", "target"]


def test_missing_flow_role_policy_is_rejected(claim_graph_module, tmp_path: Path) -> None:
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[],
        producers=[],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match="missing role policy.*SC-01"):
        build_graph(claim_graph_module, tmp_path, paths)


def test_coexistence_exceptions_are_data_not_target_aliases(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.ma11", flow_id="MA-11", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("MA-11", "A", "comparable")],
        roles=[("MA-11", "steady_state", "golden"), ("MA-11", "steady_state", "coexistence")],
        producers=[producer],
    )

    graph = build_graph(claim_graph_module, tmp_path, paths)

    assert [cell["role_id"] for cell in graph["cells"]] == ["golden", "coexistence"]
    assert "target" not in {cell["role_id"] for cell in graph["cells"]}


def test_target_only_transition_never_synthesizes_golden(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.ss10", flow_id="SS-10", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SS-10", "A", "target-only")],
        roles=[("SS-10", "transition", "coexistence->native->coexistence")],
        producers=[producer],
    )

    graph = build_graph(claim_graph_module, tmp_path, paths)

    assert len(graph["cells"]) == 1
    assert graph["cells"][0]["role_kind"] == "transition"
    assert graph["cells"][0]["role_id"] == "coexistence->native->coexistence"


@pytest.mark.parametrize(
    "roles,error",
    [
        pytest.param(
            [("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "golden")],
            "duplicate claim cell",
            id="duplicate-role-cell",
        ),
        pytest.param([("SC-01", "steady_state", "mystery")], "unknown role", id="unknown-role"),
        pytest.param([("SC-01", "transition", "target")], "unknown transition", id="flattened-transition"),
    ],
)
def test_invalid_role_policy_is_rejected(claim_graph_module, tmp_path: Path, roles, error: str) -> None:
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=roles,
        producers=[],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match=error):
        build_graph(claim_graph_module, tmp_path, paths)


@pytest.mark.parametrize(
    "producer_kwargs,error",
    [
        pytest.param({"flow_id": "UNKNOWN"}, "unknown producer flow", id="unknown-flow"),
        pytest.param({"layer": "D_ASSERT"}, "does not require layer", id="unknown-layer-for-flow"),
        pytest.param({"reuse_key": "whole-campaign"}, "invalid reuse key", id="invalid-reuse"),
        pytest.param({"reuse_key": "flow_role"}, "invalid reuse key", id="cross-cell-reuse"),
        pytest.param(
            {
                "target_bindings": [
                    {"role_kind": "steady_state", "role_id": "golden"}
                ]
            },
            "target binding mismatch",
            id="incomplete-target-binding",
        ),
        pytest.param({"create_validator": False}, "validator does not exist", id="missing-validator"),
        pytest.param({"producer_digest": "sha256:" + "1" * 64}, "stale producer digest", id="stale-producer"),
        pytest.param({"validator_digest": "sha256:" + "2" * 64}, "stale validator digest", id="stale-validator"),
    ],
)
def test_invalid_producer_contract_is_rejected(
    claim_graph_module, tmp_path: Path, producer_kwargs, error: str
) -> None:
    producer = make_producer(
        tmp_path,
        producer_id="agent.sc01",
        flow_id=producer_kwargs.pop("flow_id", "SC-01"),
        layer=producer_kwargs.pop("layer", "A"),
        **producer_kwargs,
    )
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match=error):
        build_graph(claim_graph_module, tmp_path, paths)


def test_absent_producer_is_explicit_and_preflight_is_not_ready(claim_graph_module, tmp_path: Path) -> None:
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "D", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[],
    )

    graph = build_graph(claim_graph_module, tmp_path, paths)
    report = validate_preflight(claim_graph_module, graph)

    assert {cell["producer"]["status"] for cell in graph["cells"]} == {"NO_PRODUCER"}
    assert report["verdict"] == "NOT_READY"
    assert report["missing_producer_obligations"] == ["SC-01:D"]


@pytest.mark.parametrize("mutation", ["empty", "subset", "extra"])
def test_self_digested_noncanonical_graph_is_rejected(
    claim_graph_module, tmp_path: Path, mutation: str
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    canonical = build_graph(claim_graph_module, tmp_path, paths)
    submitted = json.loads(json.dumps(canonical))
    if mutation == "empty":
        submitted["flows"] = []
        submitted["cells"] = []
    elif mutation == "subset":
        submitted["cells"] = submitted["cells"][:1]
    else:
        invented = dict(submitted["cells"][0])
        invented["cell_id"] = "UNKNOWN:A:steady_state:golden"
        invented["flow_id"] = "UNKNOWN"
        submitted["cells"].append(invented)
    submitted["graph_sha256"] = claim_graph_module.content_digest(submitted)

    with pytest.raises(claim_graph_module.ClaimGraphError, match="canonical maintained inputs"):
        validate_preflight(
            claim_graph_module,
            submitted,
            canonical_graph=canonical,
        )


@pytest.mark.parametrize("changed_file", ["agent.sc01.producer", "agent.sc01.validator"])
def test_changed_producer_or_validator_bytes_invalidate_saved_graph(
    claim_graph_module, tmp_path: Path, changed_file: str
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    build_graph(claim_graph_module, tmp_path, paths)
    (tmp_path / changed_file).write_text("changed trusted bytes\n", encoding="utf-8")

    with pytest.raises(claim_graph_module.ClaimGraphError, match="stale (producer|validator) digest"):
        build_graph(claim_graph_module, tmp_path, paths)


def test_repository_graph_pins_role_exceptions_and_exact_producer_inventory(
    claim_graph_module,
) -> None:
    graph = claim_graph_module.build_default_graph()

    assert graph["summary"]["flow_count"] == 76
    assert graph["summary"]["missing_producer_obligation_count"] == 49
    cells_by_flow = {
        flow["flow_id"]: {
            (cell["layer"], cell["role_kind"], cell["role_id"])
            for cell in graph["cells"]
            if cell["flow_id"] == flow["flow_id"]
        }
        for flow in graph["flows"]
    }
    coexistence_flows = {
        flow_id
        for flow_id, cells in cells_by_flow.items()
        if any(role_id == "coexistence" for _, _, role_id in cells)
    }
    transition_flows = {
        flow_id
        for flow_id, cells in cells_by_flow.items()
        if any(role_kind == "transition" for _, role_kind, _ in cells)
    }
    assert coexistence_flows == {"MA-11", "ON-04"}
    assert transition_flows == {"SS-10"}
    assert cells_by_flow["MA-11"] == {
        (layer, "steady_state", role)
        for layer in ("A", "D")
        for role in ("golden", "coexistence")
    }
    assert cells_by_flow["ON-04"] == {
        (layer, "steady_state", role)
        for layer in ("D", "A")
        for role in ("golden", "coexistence")
    }
    assert cells_by_flow["SS-10"] == {
        (layer, "transition", "coexistence->native->coexistence")
        for layer in ("D", "A")
    }

    bound_obligations: dict[tuple[str, str], str] = {}
    for cell in graph["cells"]:
        producer = cell["producer"]
        if producer["status"] == "BOUND":
            binding = (cell["flow_id"], cell["layer"])
            assert bound_obligations.setdefault(binding, producer["producer_id"]) == producer[
                "producer_id"
            ]
    assert len(bound_obligations) == 73
    assert len(set(bound_obligations.values())) == 73
    assert sum(layer == "A" for _, layer in bound_obligations) == 59
    assert sum(layer == "D" for _, layer in bound_obligations) == 14
    assert {flow_id for (flow_id, layer) in bound_obligations if layer == "D"} == {
        "MA-01",
        "MA-09",
        "MA-10",
        "MC-06",
        "MD-01",
        "MD-02",
        "MD-03",
        "MD-04",
        "MO-01",
        "MO-02",
        "MO-03",
        "SC-01",
        "SC-02",
        "SP-01",
    }
    missing_obligations = {
        tuple(obligation.split(":"))
        for obligation in graph["summary"]["missing_producer_obligations"]
    }
    assert not {obligation for obligation in missing_obligations if obligation[1] == "A"}
    assert sum(layer == "D" for _, layer in missing_obligations) == 46
    assert sum(layer == "D_ASSERT" for _, layer in missing_obligations) == 3
    for cell in graph["cells"]:
        producer_binding = cell["producer"]
        if producer_binding["status"] != "BOUND":
            continue
        validator_path = REPO / producer_binding["validator_path"]
        assert validator_path.is_file()
        assert producer_binding["validator_sha256"] == sha256(validator_path)


@pytest.mark.parametrize(
    "layer_grammar,missing_layer",
    [
        pytest.param("D+A", "D", id="deterministic-and-agent"),
        pytest.param("A (+D)", "D", id="agent-with-deterministic"),
        pytest.param(
            "A (+D assert)",
            "D_ASSERT",
            id="agent-with-distinct-deterministic-assertion",
        ),
    ],
)
def test_partial_runner_coverage_and_a_only_artifact_cannot_pass_mixed_layers(
    claim_graph_module, tmp_path: Path, layer_grammar: str, missing_layer: str
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", layer_grammar, "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    a_cell = next(cell for cell in graph["cells"] if cell["layer"] == "A")
    context = evidence_context(graph)
    artifact = artifact_for(claim_graph_module, tmp_path, graph, a_cell, context)

    report = validate_artifacts(
        claim_graph_module,
        graph,
        tmp_path,
        context,
        [artifact],
        covered=1,
    )

    assert report["verdict"] == "NOT_READY"
    assert f"SC-01:{missing_layer}:steady_state:golden" in report["missing_cells"]
    assert report["runner_matrix_covered"] == 1


@pytest.mark.parametrize("flow_id", ["MA-11", "ON-04"])
def test_target_artifact_cannot_satisfy_coexistence_cell(
    claim_graph_module, tmp_path: Path, flow_id: str
) -> None:
    producer = make_producer(
        tmp_path,
        producer_id=f"agent.{flow_id.lower().replace('-', '')}",
        flow_id=flow_id,
        layer="A",
    )
    paths = write_inputs(
        tmp_path,
        flows=[(flow_id, "A", "comparable")],
        roles=[
            (flow_id, "steady_state", "golden"),
            (flow_id, "steady_state", "coexistence"),
        ],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    coexistence_cell = next(cell for cell in graph["cells"] if cell["role_id"] == "coexistence")
    context = evidence_context(graph)
    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        coexistence_cell,
        context,
        role_binding_override={
            "kind": "steady_state",
            "role_id": "target",
            "store_fingerprint": "store:target",
            "account_fingerprint": "account:target",
        },
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])

    assert report["verdict"] == "NOT_READY"
    assert any("artifact role mismatch" in error for error in report["errors"])


def test_steady_state_artifact_cannot_satisfy_transition_cell(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.ss10", flow_id="SS-10", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SS-10", "A", "target-only")],
        roles=[("SS-10", "transition", "coexistence->native->coexistence")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        role_binding_override={
            "kind": "steady_state",
            "role_id": "target",
            "store_fingerprint": "store:target",
            "account_fingerprint": "account:target",
        },
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])

    assert report["verdict"] == "NOT_READY"
    assert any("artifact role mismatch" in error for error in report["errors"])


def test_duplicate_artifacts_are_non_green(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    cell = graph["cells"][0]
    context = evidence_context(graph)
    artifact = artifact_for(claim_graph_module, tmp_path, graph, cell, context)

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact, artifact])

    assert report["verdict"] == "NOT_READY"
    assert any("duplicate artifact cell" in error for error in report["errors"])


def test_final_validation_rejects_stale_producer_execution(
    claim_graph_module, tmp_path: Path
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)

    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        execution_overrides={"producer_sha256": "sha256:" + "f" * 64},
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])

    assert report["verdict"] == "NOT_READY"
    assert any("artifact execution mismatch" in error for error in report["errors"])


def test_unknown_artifact_producer_id_is_non_green(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        execution_overrides={"producer_id": "agent.unknown"},
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])

    assert report["verdict"] == "NOT_READY"
    assert any("artifact execution mismatch" in error for error in report["errors"])


def test_historical_context_or_incomplete_assertions_are_non_green(
    claim_graph_module, tmp_path: Path
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    current_context = evidence_context(graph)
    historical_context = json.loads(json.dumps(current_context))
    historical_context["context_id"] = "context:historical"
    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        historical_context,
    )

    report = validate_final(
        claim_graph_module,
        graph,
        artifact_packet(claim_graph_module, graph, current_context, [artifact]),
        artifact_root=tmp_path,
        expected_context=current_context,
    )

    assert report["verdict"] == "NOT_READY"
    assert any("artifact context mismatch" in error for error in report["errors"])


def test_final_validation_rejects_incomplete_assertions(
    claim_graph_module, tmp_path: Path
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    first_assertion = graph["cells"][0]["assertions"][0]["id"]

    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        omit_assertion_ids={first_assertion},
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])

    assert report["verdict"] == "NOT_READY"
    assert any("assertion set mismatch" in error for error in report["errors"])


def test_assertion_result_schema_must_match_contract(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    first_assertion = graph["cells"][0]["assertions"][0]["id"]
    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        assertion_schema_overrides={first_assertion: "untrusted-assertion.v1"},
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])

    assert report["verdict"] == "NOT_READY"
    assert any("assertion schema mismatch" in error for error in report["errors"])


def test_cell_scoped_artifact_id_cannot_be_reused_across_cells(
    claim_graph_module, tmp_path: Path
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifacts = [
        artifact_for(claim_graph_module, tmp_path, graph, cell, context)
        for cell in graph["cells"]
    ]
    artifacts[1]["artifact_id"] = artifacts[0]["artifact_id"]

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, artifacts)

    assert report["verdict"] == "NOT_READY"
    assert any("cell-scoped artifact reused" in error for error in report["errors"])


@pytest.mark.parametrize("mutation", ["missing", "changed"])
def test_missing_or_changed_physical_artifact_is_non_green(
    claim_graph_module, tmp_path: Path, mutation: str
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifacts = [
        artifact_for(claim_graph_module, tmp_path, graph, cell, context)
        for cell in graph["cells"]
    ]
    if mutation == "missing":
        artifacts[0]["artifact_path"] = "evidence/does-not-exist.json"
    else:
        artifact_path = tmp_path / artifacts[0]["artifact_path"]
        artifact_path.write_text(artifact_path.read_text(encoding="utf-8") + "\n", encoding="utf-8")

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, artifacts)

    assert report["verdict"] == "NOT_READY"
    assert any(
        ("artifact is missing" in error if mutation == "missing" else "artifact digest mismatch" in error)
        for error in report["errors"]
    )


def test_graph_copy_pass_summary_is_not_an_artifact(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    cell = graph["cells"][0]
    producer_binding = cell["producer"]
    fabricated = {
        "artifact_id": f"artifact:{cell['cell_id']}",
        "cell_id": cell["cell_id"],
        "flow_id": cell["flow_id"],
        "layer": cell["layer"],
        "role_kind": cell["role_kind"],
        "role_id": cell["role_id"],
        "producer_id": producer_binding["producer_id"],
        "producer_sha256": producer_binding["producer_sha256"],
        "validator_sha256": producer_binding["validator_sha256"],
        "current": True,
        "assertions": [
            {"id": contract["id"], "schema": contract["schema"], "status": "PASS"}
            for contract in cell["assertions"]
        ],
    }

    with pytest.raises(claim_graph_module.ClaimGraphError, match="artifact packet entry fields"):
        validate_artifacts(claim_graph_module, graph, tmp_path, context, [fabricated])


@pytest.mark.parametrize(
    "runner_metadata",
    [
        pytest.param({"matrix": "not-an-object"}, id="matrix-string"),
        pytest.param({"matrix": {"covered": []}}, id="covered-list"),
        pytest.param([], id="runner-list"),
    ],
)
def test_malformed_nested_packet_is_structured_invalid(
    claim_graph_module, tmp_path: Path, runner_metadata
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    packet = artifact_packet(claim_graph_module, graph, context, [])
    packet["runner_metadata"] = runner_metadata

    with pytest.raises(claim_graph_module.ClaimGraphError):
        validate_final(
            claim_graph_module,
            graph,
            packet,
            artifact_root=tmp_path,
            expected_context=context,
        )


@pytest.mark.parametrize(
    "payload,error",
    [
        pytest.param('{"value":1,"value":2}', "duplicate JSON key", id="duplicate-key"),
        pytest.param('{"value":NaN}', "non-finite JSON value", id="non-finite"),
    ],
)
def test_strict_json_reader_rejects_ambiguous_values(
    claim_graph_module, tmp_path: Path, payload: str, error: str
) -> None:
    path = tmp_path / "invalid.json"
    path.write_text(payload, encoding="utf-8")

    with pytest.raises(claim_graph_module.ClaimGraphError, match=error):
        claim_graph_module.read_json(path)


@pytest.mark.parametrize(
    "flow_id,external_id,waiver_class,local_status",
    [
        pytest.param(
            "SC-11",
            "sc11.hosted_capability",
            "account_scoped_lpm_capability",
            "FAIL",
            id="hosted-capability-local-fail",
        ),
        pytest.param(
            "ON-01",
            "on01.live_kyc",
            "live_kyc",
            "BLOCKED",
            id="settings-live-kyc-local-blocked",
        ),
        pytest.param(
            "ON-02",
            "on02.live_kyc",
            "live_kyc",
            "PENDING",
            id="lys-live-kyc-local-pending",
        ),
    ],
)
def test_external_assertion_waiver_cannot_hide_local_failure(
    claim_graph_module,
    tmp_path: Path,
    flow_id: str,
    external_id: str,
    waiver_class: str,
    local_status: str,
) -> None:
    local_id = f"{flow_id.lower().replace('-', '')}.restoration"
    assertions = mixed_assertion_contracts(flow_id)
    producer = make_producer(
        tmp_path,
        producer_id=f"agent.{flow_id.lower()}",
        flow_id=flow_id,
        layer="A",
        assertions=assertions,
    )
    paths = write_inputs(
        tmp_path,
        flows=[(flow_id, "A", "comparable")],
        roles=[(flow_id, "steady_state", "golden"), (flow_id, "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        assertion_statuses={
            local_id: local_status,
            external_id: "BLOCKED",
        },
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])

    assert report["verdict"] == "NOT_READY"
    qualified_local = f"{graph['cells'][0]['cell_id']}::{local_id}:{local_status}"
    qualified_external = (
        f"{graph['cells'][0]['cell_id']}::{external_id}:{waiver_class}"
    )
    assert qualified_local in report["non_green_assertions"]
    assert qualified_external in report["waiver_candidates"]


def test_self_accepted_waiver_is_structurally_rejected(claim_graph_module, tmp_path: Path) -> None:
    assertions = mixed_assertion_contracts("ON-01")
    producer = make_producer(
        tmp_path,
        producer_id="agent.on01",
        flow_id="ON-01",
        layer="A",
        assertions=assertions,
    )
    paths = write_inputs(
        tmp_path,
        flows=[("ON-01", "A", "comparable")],
        roles=[("ON-01", "steady_state", "golden"), ("ON-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifact = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        assertion_statuses={"on01.live_kyc": "BLOCKED"},
        assertion_evidence_extras={
            "on01.live_kyc": {
                "waiver": {"class": "live_kyc", "accepted": True}
            },
        },
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match="assertion evidence fields"):
        validate_artifacts(claim_graph_module, graph, tmp_path, context, [artifact])


def test_failed_external_assertion_cannot_be_waived(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(
        tmp_path,
        producer_id="agent.on01",
        flow_id="ON-01",
        layer="A",
        assertions=mixed_assertion_contracts("ON-01"),
    )
    paths = write_inputs(
        tmp_path,
        flows=[("ON-01", "A", "comparable")],
        roles=[("ON-01", "steady_state", "golden"), ("ON-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifacts = [
        artifact_for(claim_graph_module, tmp_path, graph, cell, context)
        for cell in graph["cells"]
    ]
    artifacts[0] = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        assertion_statuses={"on01.live_kyc": "FAIL"},
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, artifacts)

    assert report["verdict"] == "NOT_READY"
    qualified = f"{graph['cells'][0]['cell_id']}::on01.live_kyc:FAIL"
    assert qualified in report["non_green_assertions"]
    assert report["waiver_candidates"] == []


def test_waiver_class_is_authorized_for_exact_flow_assertion(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(
        tmp_path,
        producer_id="agent.sc01",
        flow_id="SC-01",
        layer="A",
        assertions=assertion_contracts(("sc01.external", "live_kyc")),
    )
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match="unauthorized waiver contract"):
        build_graph(claim_graph_module, tmp_path, paths)


@pytest.mark.parametrize(
    "field_index,payload",
    [
        pytest.param(
            8,
            '[{"role_kind":"forged","role_kind":"steady_state","role_id":"golden"},'
            '{"role_kind":"steady_state","role_id":"target"}]',
            id="target-duplicate-key",
        ),
        pytest.param(8, '[{"role_kind":NaN,"role_id":"golden"}]', id="target-non-finite"),
        pytest.param(
            10,
            '[{"id":"forged","id":"sc01.a.outcome","schema":"claim-assertion.v1",'
            '"waiver_class":"none"}]',
            id="assertion-duplicate-key",
        ),
        pytest.param(
            10,
            '[{"id":"sc01.a.outcome","schema":NaN,"waiver_class":"none"}]',
            id="assertion-non-finite",
        ),
    ],
)
def test_embedded_registry_json_is_strict(
    claim_graph_module, tmp_path: Path, field_index: int, payload: str
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    producer[field_index] = payload
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match="(duplicate JSON key|non-finite)"):
        build_graph(claim_graph_module, tmp_path, paths)


def test_unsupported_assertion_schema_is_rejected(claim_graph_module, tmp_path: Path) -> None:
    assertions = json.dumps(
        [{"id": "sc01.outcome", "schema": "untrusted.v1", "waiver_class": "none"}],
        separators=(",", ":"),
        sort_keys=True,
    )
    producer = make_producer(
        tmp_path,
        producer_id="agent.sc01",
        flow_id="SC-01",
        layer="A",
        assertions=assertions,
    )
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match="unsupported assertion schema"):
        build_graph(claim_graph_module, tmp_path, paths)


def test_mixed_external_flow_requires_every_local_assertion(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(
        tmp_path,
        producer_id="agent.on01",
        flow_id="ON-01",
        layer="A",
        assertions=assertion_contracts(("on01.live_kyc", "live_kyc")),
    )
    paths = write_inputs(
        tmp_path,
        flows=[("ON-01", "A", "comparable")],
        roles=[("ON-01", "steady_state", "golden"), ("ON-01", "steady_state", "target")],
        producers=[producer],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match="missing required mixed assertions"):
        build_graph(claim_graph_module, tmp_path, paths)


def test_blocked_external_assertion_is_only_a_cell_scoped_waiver_candidate(
    claim_graph_module, tmp_path: Path
) -> None:
    producer = make_producer(
        tmp_path,
        producer_id="agent.on01",
        flow_id="ON-01",
        layer="A",
        assertions=mixed_assertion_contracts("ON-01"),
    )
    paths = write_inputs(
        tmp_path,
        flows=[("ON-01", "A", "comparable")],
        roles=[("ON-01", "steady_state", "golden"), ("ON-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifacts = [
        artifact_for(claim_graph_module, tmp_path, graph, cell, context)
        for cell in graph["cells"]
    ]
    artifacts[0] = artifact_for(
        claim_graph_module,
        tmp_path,
        graph,
        graph["cells"][0],
        context,
        assertion_statuses={"on01.live_kyc": "BLOCKED"},
    )

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, artifacts)

    assert report["verdict"] == "NOT_READY"
    qualified = f"{graph['cells'][0]['cell_id']}::on01.live_kyc:live_kyc"
    assert report["waiver_candidates"] == [qualified]


def test_non_numeric_wave_assignment_is_rejected(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    write_tsv(
        paths["wave_map"],
        ["id", "primary_wave", "supplemental_wave", "mutation_class", "release_evidence"],
        [["SC-01", "later", "0", "local-reversible", "Evidence SC-01"]],
    )

    with pytest.raises(claim_graph_module.ClaimGraphError, match="invalid wave assignment"):
        build_graph(claim_graph_module, tmp_path, paths)


def test_complete_current_artifact_set_is_ready(claim_graph_module, tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifacts = [
        artifact_for(claim_graph_module, tmp_path, graph, cell, context)
        for cell in graph["cells"]
    ]

    report = validate_artifacts(claim_graph_module, graph, tmp_path, context, artifacts)

    assert report["verdict"] == "READY"
    assert report["missing_cells"] == []
    assert report["errors"] == []


def test_cli_final_validates_physical_evidence_and_trusted_context(
    claim_graph_module, tmp_path: Path
) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph = build_graph(claim_graph_module, tmp_path, paths)
    context = evidence_context(graph)
    artifacts = [
        artifact_for(claim_graph_module, tmp_path, graph, cell, context)
        for cell in graph["cells"]
    ]
    graph_path = tmp_path / "graph.json"
    context_path = tmp_path / "context.json"
    packet_path = tmp_path / "packet.json"
    for path, payload in (
        (graph_path, graph),
        (context_path, context),
    ):
        path.write_text(json.dumps(payload, sort_keys=True) + "\n", encoding="utf-8")

    packet = artifact_packet(claim_graph_module, graph, context, artifacts)
    packet_path.write_text(json.dumps(packet, sort_keys=True) + "\n", encoding="utf-8")

    result = subprocess.run(
        [
            sys.executable,
            str(TOOL),
            "validate-final",
            "--graph",
            str(graph_path),
            "--artifacts",
            str(packet_path),
            "--artifact-root",
            str(tmp_path),
            "--expected-context",
            str(context_path),
            "--matrix",
            str(paths["matrix"]),
            "--wave-map",
            str(paths["wave_map"]),
            "--role-policy",
            str(paths["role_policy"]),
            "--producers",
            str(paths["producers"]),
            "--source-root",
            str(tmp_path),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    assert json.loads(result.stdout)["verdict"] == "READY"


def test_cli_build_and_preflight_emit_machine_readable_results(tmp_path: Path) -> None:
    producer = make_producer(tmp_path, producer_id="agent.sc01", flow_id="SC-01", layer="A")
    paths = write_inputs(
        tmp_path,
        flows=[("SC-01", "D+A", "comparable")],
        roles=[("SC-01", "steady_state", "golden"), ("SC-01", "steady_state", "target")],
        producers=[producer],
    )
    graph_path = tmp_path / "graph.json"
    build = subprocess.run(
        [
            sys.executable,
            str(TOOL),
            "build",
            "--matrix",
            str(paths["matrix"]),
            "--wave-map",
            str(paths["wave_map"]),
            "--role-policy",
            str(paths["role_policy"]),
            "--producers",
            str(paths["producers"]),
            "--source-root",
            str(tmp_path),
            "--output",
            str(graph_path),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    preflight = subprocess.run(
        [
            sys.executable,
            str(TOOL),
            "validate-preflight",
            "--graph",
            str(graph_path),
            "--matrix",
            str(paths["matrix"]),
            "--wave-map",
            str(paths["wave_map"]),
            "--role-policy",
            str(paths["role_policy"]),
            "--producers",
            str(paths["producers"]),
            "--source-root",
            str(tmp_path),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert build.returncode == 0, build.stderr
    assert json.loads(build.stdout)["graph_sha256"] == json.loads(graph_path.read_text())["graph_sha256"]
    assert preflight.returncode == 3
    assert json.loads(preflight.stdout)["missing_producer_obligations"] == ["SC-01:D"]


def test_shared_self_test_lane_runs_release_claim_graph_suite() -> None:
    source = SELF_TESTS.read_text(encoding="utf-8")

    assert "test-release-claim-graph.py" in source
