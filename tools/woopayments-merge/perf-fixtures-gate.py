#!/usr/bin/env python3
"""Stage local WooPayments money-path fixtures for measured perf captures."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from perf_fixtures import PerfFixtureError, create_perf_fixtures


EXIT_INCOMPLETE = 3


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stage reference/target perf order fixtures.")
    parser.add_argument("--repo", required=True, help="WooCommerce target repo path.")
    parser.add_argument("--ref-wp", required=True, help="Reference WP-CLI command string.")
    parser.add_argument("--target-wp", required=True, help="Target WP-CLI command string.")
    parser.add_argument("--out", required=True, help="JSON output file.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    repo = Path(args.repo).resolve()
    scripts_dir = repo / "tools" / "woopayments-merge"
    out = Path(args.out).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)

    try:
        payload = create_perf_fixtures(repo, scripts_dir, args.ref_wp, args.target_wp)
    except PerfFixtureError as exc:
        payload = {
            "schema": "woopayments_perf_fixtures.v1",
            "status": "incomplete",
            "reason": str(exc),
            "stores": {},
        }
        out.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(f"RESULT: INCOMPLETE perf fixtures: {exc}", file=sys.stderr)
        return EXIT_INCOMPLETE

    out.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"RESULT: PASS perf fixtures staged: {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
