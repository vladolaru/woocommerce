#!/usr/bin/env bash
#
# Narrow per-surface payments query-count smoke gate (WooPayments → core merge harness).
#
# Captures query-count / time / memory on three gateway-resolution surfaces and gates
# native query counts against the reference baseline. Time and memory are contextual smoke
# data only; broader perf verification remains a separate stage gate.
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

case "$MODE" in
	capture)
		out="$(run_probe)"
		if [ -z "$out" ]; then
			echo "FAIL: probe produced no output (store unreachable)." >&2
			exit 2
		fi
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
		# Compare query counts per surface; fail if any target surface exceeds the baseline.
		# Write the current capture to a temp file outside the tracked tree (never the repo dir).
		tmp_base="${TMPDIR:?TMPDIR is required for temporary files}"
		mkdir -p "$tmp_base"
		current_json="$(mktemp "$tmp_base/perf-current.XXXXXX.json")"
		trap 'rm -f "$current_json"' EXIT
		printf '%s\n' "$out" > "$current_json"
		if command -v python3 >/dev/null 2>&1; then
			python3 - "$BASELINE" "$current_json" <<'PY'
import json, sys
base = json.load(open(sys.argv[1]))
cur  = json.load(open(sys.argv[2]))
fail = 0
for name, b in base.get("surfaces", {}).items():
    c = cur.get("surfaces", {}).get(name)
    if c is None:
        print(f"  DRIFT  surface '{name}' missing in target"); fail = 1; continue
    if c["queries"] > b["queries"]:
        print(f"  REGRESS {name}: queries {b['queries']} -> {c['queries']} (narrow query-count smoke fail)"); fail = 1
    else:
        print(f"  ok     {name}: queries {b['queries']} -> {c['queries']}, time {b['median_ms']}ms -> {c['median_ms']}ms")
sys.exit(1 if fail else 0)
PY
			rc=$?
			[ "$rc" -ne 0 ] && { echo "FAIL: narrow per-surface query-count regression."; exit 1; }
			echo "PASS: no per-surface query-count regression vs baseline."
		else
			echo "python3 not available; emitting current vs baseline for manual review:"
			echo "--- baseline ---"; cat "$BASELINE"
			echo "--- current ---";  printf '%s\n' "$out"
		fi
		;;
	*)
		echo "usage: WP='<wp runner>' perf-baseline.sh [capture|check]" >&2
		exit 2
		;;
esac
