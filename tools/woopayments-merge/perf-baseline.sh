#!/usr/bin/env bash
#
# Narrow per-surface payments query-count smoke gate (WooPayments → core merge harness).
#
# Captures first-resolution plus warmed query-count / time / memory evidence and gates
# native query/request counts against the reference baseline. One-shot time and memory are
# contextual data only; broader perf verification remains a separate stage gate.
#
#   WP="docker exec -i wcpay_wp_default wp --allow-root" perf-baseline.sh capture   # write baseline
#   WP="<target wp runner>"                              perf-baseline.sh check     # gate vs baseline
#
# Baseline file: perf-baseline.json (committed; the reference = current WooPayments behavior).

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROBE="$SELF_DIR/perf-baseline.php"
BASELINE="$SELF_DIR/perf-baseline.json"
WP="${WP:-wp}"

MODE="${1:-capture}"

run_probe() {
	# shellcheck disable=SC2086
	$WP eval-file - < "$PROBE"
}

require_python() {
	if ! command -v python3 >/dev/null 2>&1; then
		echo "FAIL: python3 is required to validate first-resolution evidence." >&2
		exit 2
	fi
}

validate_first_resolution_capture() {
	python3 - "$1" <<'PY'
import json
import sys

capture = json.load(open(sys.argv[1], encoding="utf-8"))
entry = capture.get("first_gateway_resolution")
if not isinstance(entry, dict):
    print("INCOMPLETE: first_gateway_resolution is missing from the capture.", file=sys.stderr)
    sys.exit(2)
if entry.get("status") != "measured":
    print(
        "INCOMPLETE: first_gateway_resolution is "
        f"{entry.get('status', 'invalid')} ({entry.get('reason', 'no reason recorded')}).",
        file=sys.stderr,
    )
    sys.exit(2)

metrics = entry.get("metrics")
problems = []
if not isinstance(metrics, dict):
    problems.append("metrics are missing")
else:
    if metrics.get("measurement_mode") != "first_in_process_gateway_resolution":
        problems.append(f"measurement_mode={metrics.get('measurement_mode')!r}")
    if metrics.get("gateway_preinitialized") is not False:
        problems.append("gateway_preinitialized is not false")
    if metrics.get("timing_sample_count") != 1:
        problems.append(f"timing_sample_count={metrics.get('timing_sample_count')!r}")
    if "median_ms" in metrics:
        problems.append("single sample is mislabeled median_ms")
    for field in ("queries", "external_requests", "elapsed_ms"):
        if not isinstance(metrics.get(field), (int, float)):
            problems.append(f"{field} is not numeric")

if problems:
    print(f"INCOMPLETE: invalid first_gateway_resolution: {', '.join(problems)}.", file=sys.stderr)
    sys.exit(2)
PY
}

case "$MODE" in
	capture)
		out="$(run_probe)"
		if [ -z "$out" ]; then
			echo "FAIL: probe produced no output (store unreachable)." >&2
			exit 2
		fi
		require_python
		tmp_base="${TMPDIR:?TMPDIR is required for temporary files}"
		mkdir -p "$tmp_base"
		capture_json="$(mktemp "$tmp_base/perf-baseline.XXXXXX.json")"
		trap 'rm -f "$capture_json"' EXIT
		printf '%s\n' "$out" > "$capture_json"
		validate_first_resolution_capture "$capture_json"
		validation_rc=$?
		[ "$validation_rc" -eq 0 ] || exit "$validation_rc"
		printf '%s\n' "$out" > "$BASELINE"
		echo "Baseline captured to $BASELINE:"
		printf '%s\n' "$out"
		;;
	check)
		if [ ! -f "$BASELINE" ]; then
			echo "MISSING baseline: run capture against the reference store first." >&2
			exit 2
		fi
		echo "Capturing current perf probe..."
		out="$(run_probe)"
		if [ -z "$out" ]; then
			echo "FAIL: probe produced no output (store unreachable)." >&2
			exit 2
		fi
		# Compare first-resolution query/request counts plus warmed query counts.
		# Write the current capture to a temp file outside the tracked tree (never the repo dir).
		tmp_base="${TMPDIR:?TMPDIR is required for temporary files}"
		mkdir -p "$tmp_base"
		current_json="$(mktemp "$tmp_base/perf-current.XXXXXX.json")"
		trap 'rm -f "$current_json"' EXIT
		printf '%s\n' "$out" > "$current_json"
		require_python
		python3 - "$BASELINE" "$current_json" <<'PY'
import json
import sys

base = json.load(open(sys.argv[1], encoding="utf-8"))
cur = json.load(open(sys.argv[2], encoding="utf-8"))
failures = []
incomplete = []


def first_resolution(capture, label):
    entry = capture.get("first_gateway_resolution")
    if not isinstance(entry, dict):
        incomplete.append(f"{label}: first_gateway_resolution is missing")
        return None
    if entry.get("status") != "measured":
        incomplete.append(
            f"{label}: first_gateway_resolution is {entry.get('status', 'invalid')} "
            f"({entry.get('reason', 'no reason recorded')})"
        )
        return None

    metrics = entry.get("metrics")
    if not isinstance(metrics, dict):
        incomplete.append(f"{label}: first_gateway_resolution metrics are missing")
        return None

    problems = []
    if metrics.get("measurement_mode") != "first_in_process_gateway_resolution":
        problems.append(f"measurement_mode={metrics.get('measurement_mode')!r}")
    if metrics.get("gateway_preinitialized") is not False:
        problems.append("gateway_preinitialized is not false")
    if metrics.get("timing_sample_count") != 1:
        problems.append(f"timing_sample_count={metrics.get('timing_sample_count')!r}")
    if "median_ms" in metrics:
        problems.append("single sample is mislabeled median_ms")
    for field in ("queries", "external_requests", "elapsed_ms"):
        if not isinstance(metrics.get(field), (int, float)):
            problems.append(f"{field} is not numeric")
    if problems:
        incomplete.append(f"{label}: invalid first_gateway_resolution ({', '.join(problems)})")
        return None
    return metrics


def print_query_groups(label, metrics):
    groups = metrics.get("top_query_groups", [])
    print(f"  note  gateway_first_resolution: {label} top query groups")
    if not isinstance(groups, list) or not groups:
        print("  note  gateway_first_resolution:   not captured")
        return
    for group in groups[:5]:
        if not isinstance(group, dict):
            continue
        callers = group.get("top_callers", [])
        suffix = f" callers={', '.join(map(str, callers[:3]))}" if isinstance(callers, list) and callers else ""
        print(f"  note  gateway_first_resolution:   {group.get('count', '?')}x {group.get('sql', '<unknown sql>')}{suffix}")


base_first = first_resolution(base, "baseline")
cur_first = first_resolution(cur, "target")
if base_first is not None and cur_first is not None:
    for field in ("queries", "external_requests"):
        before = base_first[field]
        after = cur_first[field]
        if after > before:
            print(f"  REGRESS gateway_first_resolution: {field} {before:g} -> {after:g} (first-resolution count fail)")
            failures.append(field)
            if field == "queries":
                print_query_groups("target", cur_first)
                print_query_groups("baseline", base_first)
        else:
            print(f"  ok     gateway_first_resolution: {field} {before:g} -> {after:g}")
    print(
        f"  note  gateway_first_resolution: elapsed_ms {base_first['elapsed_ms']:g} -> {cur_first['elapsed_ms']:g} "
        "(single first-resolution sample; diagnostic only)"
    )

for name, baseline_surface in base.get("surfaces", {}).items():
    current_surface = cur.get("surfaces", {}).get(name)
    if not isinstance(current_surface, dict):
        incomplete.append(f"target: warmed surface {name!r} is missing")
        continue
    if current_surface.get("queries", 0) > baseline_surface.get("queries", 0):
        print(
            f"  REGRESS {name}: queries {baseline_surface.get('queries', 0)} -> "
            f"{current_surface.get('queries', 0)} (narrow query-count smoke fail)"
        )
        failures.append(name)
    else:
        print(
            f"  ok     {name}: queries {baseline_surface.get('queries', 0)} -> "
            f"{current_surface.get('queries', 0)}, time {baseline_surface.get('median_ms', 0)}ms -> "
            f"{current_surface.get('median_ms', 0)}ms"
        )

if failures:
    sys.exit(1)
if incomplete:
    for message in incomplete:
        print(f"  INCOMPLETE {message}")
    sys.exit(2)
PY
		rc=$?
		if [ "$rc" -eq 2 ]; then
			echo "INCOMPLETE: first-resolution or warmed-surface evidence is invalid."
			exit 2
		fi
		if [ "$rc" -ne 0 ]; then
			echo "FAIL: narrow first-resolution/per-surface count regression."
			exit 1
		fi
		echo "PASS: no first-resolution or warmed per-surface count regression vs baseline."
		;;
	*)
		echo "usage: WP='<wp runner>' perf-baseline.sh [capture|check]" >&2
		exit 2
		;;
esac
