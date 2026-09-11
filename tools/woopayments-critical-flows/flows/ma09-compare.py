#!/usr/bin/env python3
"""Validate and compare MA-09 deterministic performance payloads."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import re
import statistics
import sys
from pathlib import Path
from typing import Any


SCHEMA = "woopayments_ma09_deterministic.v1"
QUERY_NAMES = ("page_1", "page_20", "type_filter", "date_filter")
CHECK_NAMES = (
    "route_registered",
    "dataset_minimum",
    "unique_ledger",
    "stable_ledger",
    "page_one_full",
    "deep_page_full",
    "pagination_exact",
    "type_filter_exact",
    "date_filter_exact",
)
BASE_KEYS = {
    "schema",
    "status",
    "store",
    "runtime_owner",
    "dataset_count",
    "seeded_count",
    "page_size",
    "deep_page",
    "ledger_sha256",
    "type_filter",
    "type_filter_count",
    "date_filter_after",
    "date_filter_before",
    "date_filter_count",
    "checks",
    "timings",
    "errors",
    "blockers",
}
SHA256_PATTERN = re.compile(r"^sha256:[0-9a-f]{64}$")
DATE_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")


class PayloadError(ValueError):
    """Raised when a deterministic payload is structurally invalid."""


def require(condition: bool, message: str) -> None:
    """Raise a payload error when a required condition is false."""
    if not condition:
        raise PayloadError(message)


def require_string_list(payload: dict[str, Any], key: str) -> list[str]:
    """Return a strict list of non-empty strings."""
    value = payload.get(key)
    require(isinstance(value, list), f"{key} must be a list")
    require(all(isinstance(item, str) and item.strip() for item in value), f"{key} must contain strings")
    return value


def validate_timing(name: str, timing: Any, *, allow_incomplete: bool = False) -> dict[str, Any]:
    """Validate one position-preserving three-sample timing record."""
    require(isinstance(timing, dict), f"timings.{name} must be an object")
    samples = timing.get("samples_seconds")
    require(isinstance(samples, list) and len(samples) == 3, f"timings.{name} must contain three samples")
    require(
        all(
            value is None
            or (isinstance(value, (int, float)) and not isinstance(value, bool))
            for value in samples
        ),
        f"timings.{name} samples must be numbers or null",
    )
    require(allow_incomplete or None not in samples, f"timings.{name} pass samples must be complete")
    numeric_samples = [float(value) if value is not None else None for value in samples]
    require(
        all(value is None or (math.isfinite(value) and value > 0) for value in numeric_samples),
        f"timings.{name} samples must be finite and positive",
    )
    median = timing.get("measured_median_seconds")
    measured_samples = [value for value in numeric_samples[1:] if value is not None]
    if len(measured_samples) == 2:
        require(
            isinstance(median, (int, float)) and not isinstance(median, bool),
            f"timings.{name} median must be numeric",
        )
        numeric_median = float(median)
        expected_median = statistics.median(measured_samples)
        require(
            math.isfinite(numeric_median)
            and numeric_median > 0
            and math.isclose(numeric_median, expected_median, rel_tol=1e-9, abs_tol=1e-9),
            f"timings.{name} median does not match the measured samples",
        )
    else:
        require(allow_incomplete and median is None, f"timings.{name} incomplete median must be null")
        numeric_median = None
    return {
        "samples_seconds": numeric_samples,
        "measured_median_seconds": numeric_median,
    }


def validate_payload(payload: Any, expected_store: str) -> dict[str, Any]:
    """Validate one strict MA-09 payload and return its normalized form."""
    require(isinstance(payload, dict), "payload must be an object")
    extra_keys = set(payload) - BASE_KEYS
    require(extra_keys in (set(), {"runner_run_stamp", "normalized_sha256"}), "payload contains unexpected fields")
    require(payload.get("schema") == SCHEMA, "payload schema is invalid")
    require(payload.get("store") == expected_store, "payload store does not match the runner store")
    expected_owner = "plugin" if expected_store == "ref" else "native"
    require(payload.get("runtime_owner") == expected_owner, "payload runtime owner is invalid")

    status = payload.get("status")
    require(status in {"pass", "fail", "blocked"}, "payload status is invalid")
    for key in ("dataset_count", "seeded_count", "page_size", "deep_page", "type_filter_count", "date_filter_count"):
        require(isinstance(payload.get(key), int) and not isinstance(payload.get(key), bool), f"{key} must be an integer")
        require(payload[key] >= 0, f"{key} must not be negative")
    require(payload["dataset_count"] == payload["seeded_count"], "seeded_count must record the exact dataset count")
    require(payload["page_size"] == 25, "page_size must preserve the default 25-row shape")
    require(payload["deep_page"] == 20, "deep_page must be page 20")
    require(isinstance(payload.get("ledger_sha256"), str) and SHA256_PATTERN.fullmatch(payload["ledger_sha256"]), "ledger digest is invalid")
    require(payload.get("type_filter") == "charge", "type_filter must be charge")
    for key in ("date_filter_after", "date_filter_before"):
        require(isinstance(payload.get(key), str) and DATE_PATTERN.fullmatch(payload[key]), f"{key} is invalid")
    require(payload["date_filter_after"] < payload["date_filter_before"], "date filter bounds are not ordered")

    checks = payload.get("checks")
    require(isinstance(checks, dict), "checks must be an object")
    require(set(checks) == set(CHECK_NAMES), "checks must contain the exact MA-09 check set")
    require(all(isinstance(checks[name], bool) for name in CHECK_NAMES), "every check must be boolean")

    errors = require_string_list(payload, "errors")
    blockers = require_string_list(payload, "blockers")
    timings = payload.get("timings")
    require(isinstance(timings, dict), "timings must be an object")
    normalized_timings: dict[str, Any] = {}
    if status != "blocked":
        require(set(timings) == set(QUERY_NAMES), "non-blocked payloads require the exact timing set")
        normalized_timings = {
            name: validate_timing(name, timings[name], allow_incomplete=status == "fail")
            for name in QUERY_NAMES
        }
    else:
        require(set(timings).issubset(set(QUERY_NAMES)), "blocked payload contains an unknown timing")
        normalized_timings = {
            name: validate_timing(name, timings[name], allow_incomplete=True) for name in timings
        }

    if status == "pass":
        require(all(checks.values()), "pass payload contains a failed state check")
        require(not errors and not blockers, "pass payload contains diagnostics")
        require(payload["type_filter_count"] > 0, "type filter must select at least one row")
        require(payload["date_filter_count"] > 0, "date filter must select at least one row")
    elif status == "fail":
        require(not blockers, "fail payload contains a blocker")
        require(errors or not all(checks.values()), "fail payload has no failed postcondition")
    else:
        require(bool(blockers), "blocked payload has no blocker")

    normalized = dict(payload)
    normalized["timings"] = normalized_timings
    return normalized


def read_payload(path: Path, expected_store: str) -> dict[str, Any]:
    """Read and validate one on-disk payload."""
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise PayloadError(f"could not read {expected_store} payload: {error}") from error
    normalized = validate_payload(payload, expected_store)
    digest = normalized.get("normalized_sha256")
    run_stamp = normalized.get("runner_run_stamp")
    require(isinstance(run_stamp, str) and run_stamp, f"{expected_store} payload has no runner stamp")
    require(isinstance(digest, str) and SHA256_PATTERN.fullmatch(digest), f"{expected_store} normalized digest is invalid")
    canonical = dict(normalized)
    del canonical["normalized_sha256"]
    encoded = json.dumps(canonical, sort_keys=True, separators=(",", ":"))
    expected_digest = "sha256:" + hashlib.sha256(encoded.encode("utf-8")).hexdigest()
    require(digest == expected_digest, f"{expected_store} normalized digest does not match its payload")
    return normalized


def normalize_command(args: argparse.Namespace) -> int:
    """Normalize stdin and bind it to the current runner invocation."""
    try:
        raw = sys.stdin.read()
        decoder = json.JSONDecoder()
        candidates: list[Any] = []
        for index, character in enumerate(raw):
            if character != "{":
                continue
            try:
                candidate, _ = decoder.raw_decode(raw[index:])
            except json.JSONDecodeError:
                continue
            if isinstance(candidate, dict) and candidate.get("schema") == SCHEMA:
                candidates.append(candidate)
        require(len(candidates) == 1, "driver output must contain exactly one MA-09 payload")
        payload = validate_payload(candidates[0], args.store)
    except PayloadError as error:
        print(f"invalid MA-09 payload: {error}", file=sys.stderr)
        return 3
    payload["runner_run_stamp"] = args.run_stamp
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":"))
    payload["normalized_sha256"] = "sha256:" + hashlib.sha256(encoded.encode("utf-8")).hexdigest()
    print(json.dumps(payload, sort_keys=True, separators=(",", ":")))
    return 0


def comparison_result(
    status: str,
    errors: list[str],
    blockers: list[str],
    ratios: dict[str, list[float]],
    run_stamp: str,
    reference_digest: str = "",
    target_digest: str = "",
) -> dict[str, Any]:
    """Build the strict comparison result envelope."""
    return {
        "schema": "woopayments_ma09_comparison.v1",
        "status": status,
        "errors": errors,
        "blockers": blockers,
        "target_to_reference_ratios": ratios,
        "runner_run_stamp": run_stamp,
        "reference_normalized_sha256": reference_digest,
        "target_normalized_sha256": target_digest,
    }


def compare_command(args: argparse.Namespace) -> int:
    """Compare the target timings with the same-run reference oracle."""
    reference_digest = ""
    target_digest = ""
    try:
        reference = read_payload(args.reference, "ref")
        target = read_payload(args.target, "target")
        require(reference.get("runner_run_stamp") == args.run_stamp, "reference payload is stale")
        require(target.get("runner_run_stamp") == args.run_stamp, "target payload is stale")
        reference_digest = reference["normalized_sha256"]
        target_digest = target["normalized_sha256"]
    except PayloadError as error:
        result = comparison_result("blocked", [], [str(error)], {}, args.run_stamp, reference_digest, target_digest)
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 3

    blockers: list[str] = []
    errors: list[str] = []
    ratios: dict[str, list[float]] = {}
    if reference["status"] == "blocked":
        blockers.append("The reference dataset or endpoint prerequisites are unavailable.")
    if target["status"] == "blocked":
        blockers.append("The target dataset or endpoint prerequisites are unavailable.")
    if reference["dataset_count"] != target["dataset_count"]:
        blockers.append("Reference and target dataset counts are not identical.")
    if reference["seeded_count"] != target["seeded_count"]:
        blockers.append("Reference and target recorded seeded counts are not identical.")
    if reference["dataset_count"] < 500 or target["dataset_count"] < 500:
        blockers.append("Both stores require at least 500 transactions.")
    for key in ("type_filter", "date_filter_after", "date_filter_before"):
        if reference[key] != target[key]:
            blockers.append(f"Reference and target {key} probes are not comparable.")

    if blockers:
        result = comparison_result("blocked", [], blockers, {}, args.run_stamp, reference_digest, target_digest)
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 3

    if reference["status"] == "fail":
        errors.append("The reference endpoint failed its deterministic state or absolute-time contract.")
    if target["status"] == "fail":
        errors.append("The target endpoint failed its deterministic state or absolute-time contract.")

    for store_name, payload in (("Reference", reference), ("Target", target)):
        for query in QUERY_NAMES:
            for index, sample in enumerate(payload["timings"][query]["samples_seconds"], start=1):
                if sample is not None and sample > 10:
                    errors.append(f"{store_name} {query} sample {index} exceeded 10 seconds.")

    for query in QUERY_NAMES:
        reference_median = reference["timings"][query]["measured_median_seconds"]
        target_samples = [
            sample
            for sample in target["timings"][query]["samples_seconds"][1:]
            if sample is not None
        ]
        if reference_median is None:
            ratios[query] = []
            continue
        ratios[query] = [sample / reference_median for sample in target_samples]
        for index, sample in enumerate(target_samples, start=2):
            if sample > 2 * reference_median:
                errors.append(
                    f"Target {query} sample {index} took {sample:.6f}s, exceeding 2x the {reference_median:.6f}s reference median."
                )

    status = "fail" if errors else "pass"
    result = comparison_result(status, errors, [], ratios, args.run_stamp, reference_digest, target_digest)
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 1 if errors else 0


def build_parser() -> argparse.ArgumentParser:
    """Build the CLI parser."""
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)
    normalize = subparsers.add_parser("normalize")
    normalize.add_argument("--store", choices=("ref", "target"), required=True)
    normalize.add_argument("--run-stamp", required=True)
    normalize.set_defaults(callback=normalize_command)
    compare = subparsers.add_parser("compare")
    compare.add_argument("--reference", type=Path, required=True)
    compare.add_argument("--target", type=Path, required=True)
    compare.add_argument("--run-stamp", required=True)
    compare.set_defaults(callback=compare_command)
    return parser


def main() -> int:
    """Run the requested payload operation."""
    args = build_parser().parse_args()
    return args.callback(args)


if __name__ == "__main__":
    raise SystemExit(main())
