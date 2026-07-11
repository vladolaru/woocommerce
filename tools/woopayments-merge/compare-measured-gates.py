#!/usr/bin/env python3
"""Compare first-pass measured perf/bundle gate captures.

The comparator is intentionally conservative: measured regressions fail, and
known-unmeasured required surfaces make the result incomplete instead of PASS.
"""

from __future__ import annotations

import argparse
import json
import sys
from typing import Any


SCHEMA = "woopayments_measured_gate.v1"
EXIT_FAIL = 1
EXIT_INCOMPLETE = 3
AUTOLOAD_TRUE = {"yes", "on", "auto-on"}
MONEY_QUERY_ABSOLUTE_BUDGET = 1
MONEY_QUERY_RELATIVE_BUDGET = 1.25
TIMING_ABSOLUTE_BUDGET_MS = 25.0
TIMING_RELATIVE_BUDGET = 1.5


def load_json(path: str) -> dict[str, Any]:
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
    except OSError as exc:
        raise SystemExit(f"ERROR: cannot read {path}: {exc}") from exc
    except json.JSONDecodeError as exc:
        raise SystemExit(f"ERROR: invalid JSON in {path}: {exc}") from exc
    if not isinstance(data, dict):
        raise SystemExit(f"ERROR: {path} must contain a JSON object")
    return data


def load_budget(path: str | None) -> dict[str, Any]:
    if path is None:
        return {}
    data = load_json(path)
    if not isinstance(data.get("assets", {}), dict):
        raise SystemExit("ERROR: budget assets must be an object")
    return data


def validate_capture(data: dict[str, Any], expected_mode: str, label: str) -> None:
    if data.get("schema") != SCHEMA:
        raise SystemExit(f"ERROR: {label} schema is {data.get('schema')!r}, expected {SCHEMA!r}")
    if data.get("mode") != expected_mode:
        raise SystemExit(f"ERROR: {label} mode is {data.get('mode')!r}, expected {expected_mode!r}")


def asset_budget(asset: str, budget: dict[str, Any]) -> dict[str, Any]:
    assets = budget.get("assets", {})
    merged: dict[str, Any] = {}
    if isinstance(assets.get("*"), dict):
        merged.update(assets["*"])
    if isinstance(assets.get(asset), dict):
        merged.update(assets[asset])
    return merged


def metric_limit(asset: str, metric: str, ref_value: int, budget: dict[str, Any]) -> int:
    rule = asset_budget(asset, budget)
    absolute = rule.get(metric)
    metric_name = metric[:-6] if metric.endswith("_bytes") else metric
    growth = rule.get(f"{metric_name}_growth_bytes")
    if isinstance(absolute, int):
        return absolute
    if isinstance(growth, int):
        return ref_value + growth
    return ref_value


def compare_bundle(args: argparse.Namespace) -> int:
    ref = load_json(args.ref)
    target = load_json(args.target)
    budget = load_budget(args.budget)
    validate_capture(ref, "bundle", "ref")
    validate_capture(target, "bundle", "target")

    ref_assets = ref.get("assets", {})
    target_assets = target.get("assets", {})
    if not isinstance(ref_assets, dict) or not isinstance(target_assets, dict):
        raise SystemExit("ERROR: bundle captures must contain an assets object")

    failures: list[str] = []
    infos: list[str] = []
    for asset in sorted(set(ref_assets) | set(target_assets)):
        r = ref_assets.get(asset, {"status": "missing"})
        t = target_assets.get(asset, {"status": "missing"})
        if not isinstance(r, dict) or not isinstance(t, dict):
            failures.append(f"{asset}: invalid asset entry")
            continue

        r_present = r.get("status") == "present"
        t_present = t.get("status") == "present"
        rule = asset_budget(asset, budget)

        if r_present and not t_present:
            if rule.get("allow_missing") is True:
                infos.append(f"ALLOW {asset}: present in ref, missing in target")
            else:
                failures.append(f"{asset}: present in ref, missing in target")
            continue
        if not r_present and t_present:
            if rule.get("allow_new") is True:
                infos.append(f"ALLOW {asset}: missing in ref, present in target")
                if not any(isinstance(rule.get(metric), int) for metric in ("raw_bytes", "gzip_bytes")):
                    failures.append(f"{asset}: allow_new requires explicit raw_bytes/gzip_bytes ceilings")
                    continue
            else:
                failures.append(f"{asset}: missing in ref, present in target")
                continue
        if not r_present and not t_present:
            infos.append(f"skip  {asset}: missing in both captures")
            continue

        for metric in ("raw_bytes", "gzip_bytes"):
            r_value = int(r.get(metric, 0))
            t_value = int(t.get(metric, 0))
            limit = metric_limit(asset, metric, r_value, budget)
            if t_value > limit:
                failures.append(f"{asset}: {metric} {r_value} -> {t_value} exceeds limit {limit}")
            else:
                infos.append(f"ok    {asset}: {metric} {r_value} -> {t_value} (limit {limit})")

    for line in infos:
        print(line)
    if failures:
        for line in failures:
            print(f"FAIL  {line}")
        print("RESULT: FAIL bundle byte gate")
        return EXIT_FAIL

    if args.budget:
        print(f"RESULT: PASS bundle byte gate with explicit budget {args.budget}")
    else:
        print("RESULT: PASS bundle byte gate")
    return 0


def probe(data: dict[str, Any], name: str) -> dict[str, Any]:
    probes = data.get("probes", {})
    if not isinstance(probes, dict):
        return {"status": "missing", "reason": "capture has no probes object"}
    entry = probes.get(name, {"status": "missing"})
    return entry if isinstance(entry, dict) else {"status": "invalid"}


def metric(entry: dict[str, Any], name: str, default: int | float = 0) -> int | float:
    metrics = entry.get("metrics", {})
    if not isinstance(metrics, dict):
        return default
    value = metrics.get(name, default)
    return value if isinstance(value, (int, float)) else default


def optional_metric(entry: dict[str, Any], name: str) -> int | float | None:
    metrics = entry.get("metrics", {})
    if not isinstance(metrics, dict):
        return None
    value = metrics.get(name)
    return value if isinstance(value, (int, float)) else None


def has_rest_route_snapshot(entry: dict[str, Any]) -> bool:
    return metric(entry, "route_count") > 0 and metric(entry, "payment_route_count") > 0


def tolerant_limit(field: str, ref_value: int | float) -> int | float:
    if field == "queries":
        return max(ref_value + MONEY_QUERY_ABSOLUTE_BUDGET, ref_value * MONEY_QUERY_RELATIVE_BUDGET)
    if field == "median_ms":
        return max(ref_value + TIMING_ABSOLUTE_BUDGET_MS, ref_value * TIMING_RELATIVE_BUDGET)
    return ref_value


def query_group_notes(name: str, label: str, entry: dict[str, Any]) -> list[str]:
    metrics = entry.get("metrics", {})
    groups = metrics.get("top_query_groups") if isinstance(metrics, dict) else []
    if not isinstance(groups, list) or not groups:
        return [f"note  {name}: {label} top query groups not captured"]

    notes = [f"note  {name}: {label} top query groups"]
    for group in groups[:5]:
        if not isinstance(group, dict):
            continue
        count = group.get("count", "?")
        sql = str(group.get("sql", "<unknown sql>"))
        callers = group.get("top_callers", [])
        caller_suffix = ""
        if isinstance(callers, list) and callers:
            caller_suffix = f" callers={', '.join(str(caller) for caller in callers[:3])}"
        notes.append(f"note  {name}:   {count}x {sql}{caller_suffix}")
    return notes


def compare_perf_probe_metrics(
    name: str,
    ref_entry: dict[str, Any],
    target_entry: dict[str, Any],
    fields: tuple[str, ...],
    failures: list[str],
    incomplete: list[str],
    infos: list[str],
) -> None:
    if target_entry.get("status") != "measured":
        reason = target_entry.get("reason", "not measured")
        incomplete.append(f"{name}: {target_entry.get('status')} ({reason})")
        return
    if ref_entry.get("status") != "measured":
        reason = ref_entry.get("reason", "not measured")
        incomplete.append(f"{name}: ref {ref_entry.get('status')} ({reason})")
        return
    if metric(target_entry, "external_requests") <= 0:
        incomplete.append(f"{name}: measured without target provider-boundary HTTP")
        return
    if metric(ref_entry, "external_requests") <= 0:
        incomplete.append(f"{name}: measured without ref provider-boundary HTTP")
        return

    for field in fields:
        rv = metric(ref_entry, field)
        tv = metric(target_entry, field)
        limit = tolerant_limit(field, rv)
        if tv > limit:
            failures.append(f"{name}: {field} {rv} -> {tv} exceeds limit {limit:g}")
            if field == "queries":
                infos.extend(query_group_notes(name, "target", target_entry))
                infos.extend(query_group_notes(name, "ref", ref_entry))
        else:
            if limit != rv:
                infos.append(f"ok    {name}: {field} {rv} -> {tv} (limit {limit:g})")
            else:
                infos.append(f"ok    {name}: {field} {rv} -> {tv}")


def compare_money_timing(
    name: str,
    ref_entry: dict[str, Any],
    target_entry: dict[str, Any],
    failures: list[str],
    infos: list[str],
) -> None:
    if ref_entry.get("status") != "measured" or target_entry.get("status") != "measured":
        return

    ref_samples = int(metric(ref_entry, "timing_sample_count", 1))
    target_samples = int(metric(target_entry, "timing_sample_count", 1))
    if ref_samples >= 3 and target_samples >= 3:
        rv = metric(ref_entry, "median_ms")
        tv = metric(target_entry, "median_ms")
        limit = tolerant_limit("median_ms", rv)
        if tv > limit:
            failures.append(f"{name}: median_ms {rv} -> {tv} exceeds limit {limit:g}")
        else:
            infos.append(f"ok    {name}: median_ms {rv} -> {tv} (limit {limit:g}; {ref_samples}/{target_samples} samples)")
        return

    ref_elapsed = optional_metric(ref_entry, "elapsed_ms")
    target_elapsed = optional_metric(target_entry, "elapsed_ms")
    if ref_elapsed is None:
        ref_elapsed = optional_metric(ref_entry, "median_ms")
    if target_elapsed is None:
        target_elapsed = optional_metric(target_entry, "median_ms")
    if ref_elapsed is not None and target_elapsed is not None:
        infos.append(
            f"note  {name}: elapsed_ms {ref_elapsed} -> {target_elapsed} "
            "(single invocation; diagnostic only)"
        )


def compare_perf(args: argparse.Namespace) -> int:
    ref = load_json(args.ref)
    target = load_json(args.target)
    validate_capture(ref, "perf", "ref")
    validate_capture(target, "perf", "target")

    print(
        "NOTE: perf timings are coarse local smoke signals with expected variance; "
        "this gate catches query/request growth, repeated-probe timing deltas, and missing coverage, "
        "not exact one-shot money-path latency."
    )

    failures: list[str] = []
    incomplete: list[str] = []
    infos: list[str] = []

    for name in ("process_payment", "refund", "capture"):
        ref_entry = probe(ref, name)
        target_entry = probe(target, name)
        compare_perf_probe_metrics(
            name,
            ref_entry,
            target_entry,
            ("queries", "external_requests"),
            failures,
            incomplete,
            infos,
        )
        compare_money_timing(name, ref_entry, target_entry, failures, infos)

    gateway_ref = probe(ref, "gateway_registration")
    gateway_target = probe(target, "gateway_registration")
    if gateway_ref.get("status") == "measured" and gateway_target.get("status") == "measured":
        dupes = gateway_target.get("metrics", {}).get("duplicate_gateway_ids", [])
        if dupes:
            failures.append(f"gateway_registration: duplicate gateway ids in target: {', '.join(dupes)}")
        for field in ("gateway_count", "action_callback_count", "external_requests"):
            rv = metric(gateway_ref, field)
            tv = metric(gateway_target, field)
            if tv > rv:
                failures.append(f"gateway_registration: {field} {rv} -> {tv}")
            else:
                infos.append(f"ok    gateway_registration: {field} {rv} -> {tv}")
        ref_mode = gateway_ref.get("metrics", {}).get("measurement_mode", "unknown")
        target_mode = gateway_target.get("metrics", {}).get("measurement_mode", "unknown")
        infos.append(f"note  gateway_registration: measurement_mode ref={ref_mode} target={target_mode}")
        rv = metric(gateway_ref, "median_ms")
        tv = metric(gateway_target, "median_ms")
        limit = tolerant_limit("median_ms", rv)
        if tv > limit:
            failures.append(f"gateway_registration: median_ms {rv} -> {tv} exceeds large-delta smoke limit {limit:g}")
        else:
            infos.append(f"ok    gateway_registration: median_ms {rv} -> {tv} (large-delta smoke limit {limit:g})")
    else:
        incomplete.append("gateway_registration: missing measured ref or target probe")

    rest_ref = probe(ref, "rest_boot")
    rest_target = probe(target, "rest_boot")
    if rest_ref.get("status") == "measured" and rest_target.get("status") == "measured":
        rest_ref_metrics = rest_ref.get("metrics", {})
        rest_target_metrics = rest_target.get("metrics", {})
        for label, metrics in (("ref", rest_ref_metrics), ("target", rest_target_metrics)):
            if isinstance(metrics, dict) and metrics.get("controller_instantiation_status") != "measured":
                incomplete.append(
                    "rest_boot: controller_instantiation_count "
                    f"{label}=({metrics.get('controller_instantiation_status', 'not measured')})"
                )
        ref_route_registration = (
            rest_ref_metrics.get("route_registration_status", "measured")
            if isinstance(rest_ref_metrics, dict)
            else "not measured"
        )
        target_route_registration = (
            rest_target_metrics.get("route_registration_status", "measured")
            if isinstance(rest_target_metrics, dict)
            else "not measured"
        )
        route_registration_measured = ref_route_registration == "measured" and target_route_registration == "measured"
        route_registration_snapshot = (
            ref_route_registration == "preinitialized"
            and target_route_registration == "preinitialized"
            and has_rest_route_snapshot(rest_ref)
            and has_rest_route_snapshot(rest_target)
        )
        if not route_registration_measured and not route_registration_snapshot:
            incomplete.append(
                "rest_boot: route_registration_status "
                f"ref={ref_route_registration} target={target_route_registration}"
            )
        elif route_registration_snapshot:
            infos.append(
                "note  rest_boot: route registration preinitialized in both captures; "
                "comparing route/controller snapshot evidence and skipping isolated timing"
            )
            infos.append(
                "ok    rest_boot: payment_route_count "
                f"{metric(rest_ref, 'payment_route_count')} -> {metric(rest_target, 'payment_route_count')}"
            )
        for field in ("queries", "external_requests", "controller_instantiation_count"):
            rv = metric(rest_ref, field)
            tv = metric(rest_target, field)
            if tv > rv:
                failures.append(f"rest_boot: {field} {rv} -> {tv}")
            else:
                infos.append(f"ok    rest_boot: {field} {rv} -> {tv}")
        if route_registration_measured:
            timing_valid = True
            for label, metrics in (("ref", rest_ref_metrics), ("target", rest_target_metrics)):
                problems = []
                if not isinstance(metrics, dict):
                    problems.append("metrics missing")
                else:
                    if metrics.get("measurement_mode") != "single_invocation_rest_api_init":
                        problems.append(f"measurement_mode={metrics.get('measurement_mode')!r}")
                    if metrics.get("timing_sample_count") != 1:
                        problems.append(f"timing_sample_count={metrics.get('timing_sample_count')!r}")
                    if "median_ms" in metrics:
                        problems.append("single sample is mislabeled median_ms")
                    if not isinstance(metrics.get("elapsed_ms"), (int, float)):
                        problems.append("elapsed_ms is not numeric")
                if problems:
                    incomplete.append(f"rest_boot: invalid {label} one-shot timing ({', '.join(problems)})")
                    timing_valid = False

            if timing_valid:
                infos.append(
                    "note  rest_boot: elapsed_ms "
                    f"{metric(rest_ref, 'elapsed_ms')} -> {metric(rest_target, 'elapsed_ms')} "
                    "(single invocation; diagnostic only)"
                )
    else:
        incomplete.append("rest_boot: missing measured ref or target probe")

    autoload_ref = probe(ref, "autoload_options")
    autoload_target = probe(target, "autoload_options")
    if autoload_ref.get("status") == "measured" and autoload_target.get("status") == "measured":
        rv = metric(autoload_ref, "autoload_bytes")
        tv = metric(autoload_target, "autoload_bytes")
        if tv > rv:
            failures.append(f"autoload_options: autoload_bytes {rv} -> {tv}")
        else:
            infos.append(f"ok    autoload_options: autoload_bytes {rv} -> {tv}")
    else:
        incomplete.append("autoload_options: missing measured ref or target probe")

    account_target = probe(target, "wcpay_account_data")
    if account_target.get("status") == "measured":
        autoload = str(account_target.get("metrics", {}).get("autoload", "")).lower()
        if autoload in AUTOLOAD_TRUE:
            failures.append(f"wcpay_account_data: autoload is {autoload!r}, expected non-autoloaded")
        else:
            infos.append(f"ok    wcpay_account_data: autoload={autoload or '<missing>'}")
    else:
        incomplete.append("wcpay_account_data: missing measured target probe")

    for line in infos:
        print(line)
    if failures:
        for line in failures:
            print(f"FAIL  {line}")
        print("RESULT: FAIL perf measured gate")
        return EXIT_FAIL
    if incomplete:
        for line in incomplete:
            print(f"INCOMPLETE {line}")
        print("RESULT: INCOMPLETE perf gate (required surfaces are explicitly unmeasured)")
        return EXIT_INCOMPLETE

    print("RESULT: PASS perf measured gate")
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="mode", required=True)

    bundle = sub.add_parser("bundle", help="compare bundle capture JSON")
    bundle.add_argument("--ref", required=True)
    bundle.add_argument("--target", required=True)
    bundle.add_argument("--budget", help="explicit JSON budget/allowlist")
    bundle.set_defaults(func=compare_bundle)

    perf = sub.add_parser("perf", help="compare perf capture JSON")
    perf.add_argument("--ref", required=True)
    perf.add_argument("--target", required=True)
    perf.set_defaults(func=compare_perf)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
