#!/usr/bin/env bash
#
# Harness self-test entrypoint — the fail-closed half of the trust gate.
#
# verify.sh --self-check proves the gates return PASS on identical inputs (no false
# positives). This script runs the regression suites that prove the OTHER half —
# that the gates still detect injected differences and still fail closed. A green
# verify loop without these is only half a trust story: a fail-open regression in a
# gate script would keep the loop green indefinitely.
#
# Runs:
#   1. Both pytest suites (tools/woopayments-merge + tools/woopayments-critical-flows).
#      Dash-named test files are collected via tools/pytest.ini (python_files); the
#      suites are hermetic — every store/provider transport is a per-test fake.
#   2. The shell fixture self-tests under tests/ (financial-reconcile comparator,
#      measured-gate comparators, A4 admin-surface gate fixtures).
#
# Exit: 0 all green · 1 any failure · 2 preconditions missing.
# Typical runtime: ~8 minutes (dominated by the merge pytest suite).
#
#   tools/woopayments-merge/run-self-tests.sh

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SELF_DIR/../.." && pwd)"
CRITICAL_FLOWS_DIR="$REPO_ROOT/tools/woopayments-critical-flows"

if ! command -v python3 >/dev/null 2>&1; then
	echo "BLOCKED: python3 is required for the harness self-tests." >&2
	exit 2
fi
if ! python3 -c 'import pytest' >/dev/null 2>&1; then
	echo "BLOCKED: pytest is required for the harness self-tests (python3 -m pip install pytest)." >&2
	exit 2
fi
if [ ! -d "$CRITICAL_FLOWS_DIR" ]; then
	echo "BLOCKED: missing critical-flows suite: $CRITICAL_FLOWS_DIR" >&2
	exit 2
fi

# The A4 fixture script requires TMPDIR; provide a session-scoped default.
export TMPDIR="${TMPDIR:-$(mktemp -d)}"

fail=0

step() {
	local label="$1"
	shift
	echo "== self-tests: $label"
	if "$@"; then
		echo "== self-tests: $label OK"
	else
		echo "== self-tests: $label FAILED (exit $?)" >&2
		fail=1
	fi
	echo
}

# pytest exits 5 on zero collected tests, which lands in the failure branch above —
# a mis-collection (e.g. the python_files config regressing) can never read as green.
step "pytest (merge + critical-flows suites)" \
	python3 -m pytest -q "$SELF_DIR" "$CRITICAL_FLOWS_DIR"

step "financial-reconcile comparator fixtures" \
	bash "$SELF_DIR/tests/financial-reconcile-fixtures.sh"

step "measured-gate comparator fixtures" \
	bash "$SELF_DIR/tests/compare-measured-gates-fixtures.sh"

step "A4 admin-surface gate fixtures" \
	bash "$SELF_DIR/tests/a4-admin-surface-fixtures.sh"

if [ "$fail" -ne 0 ]; then
	echo "FAIL: harness self-tests found regressions in the gates themselves. Do not trust green gate output until fixed." >&2
	exit 1
fi
echo "PASS: harness self-tests green (gate fail-closed behavior verified)."
exit 0
