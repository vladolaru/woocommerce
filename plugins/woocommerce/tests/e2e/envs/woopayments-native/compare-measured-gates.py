#!/usr/bin/env python3
"""Compare first-pass measured WooPayments bundle-size gate captures.

The comparator is intentionally conservative: any measured regression fails.
"""

from __future__ import annotations

import argparse
import json
import sys
from typing import Any


SCHEMA = "woopayments_measured_gate.v1"
EXIT_FAIL = 1


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


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="mode", required=True)

    bundle = sub.add_parser("bundle", help="compare bundle capture JSON")
    bundle.add_argument("--ref", required=True)
    bundle.add_argument("--target", required=True)
    bundle.add_argument("--budget", help="explicit JSON budget/allowlist")
    bundle.set_defaults(func=compare_bundle)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
