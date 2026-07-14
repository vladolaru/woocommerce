#!/usr/bin/env bash
#
# Native hook naming gate (static, source-level).
#
# Enforces the native hook naming contract pinned in test-native-hook-naming-gate.py:
# the exact woocommerce_woopayments_* filter/emission-site inventory, absence of
# obsolete spellings, and exclusion of target-only hooks from the reference parity
# driver. The pytest module IS the contract; this wrapper gives it the standard gate
# shape (exit 0 PASS / 1 FAIL / 2 BLOCKED) so verify.sh can run it fail-closed.
#
#   tools/woopayments-merge/native-hook-naming-gate.sh

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTRACT="$SELF_DIR/test-native-hook-naming-gate.py"

if ! command -v python3 >/dev/null 2>&1; then
	echo "BLOCKED: python3 is required for the native hook naming gate." >&2
	exit 2
fi
if [ ! -f "$CONTRACT" ]; then
	echo "BLOCKED: missing hook naming contract module: $CONTRACT" >&2
	exit 2
fi
if ! python3 -c 'import pytest' >/dev/null 2>&1; then
	echo "BLOCKED: pytest is required for the native hook naming gate (python3 -m pip install pytest)." >&2
	exit 2
fi

python3 -m pytest -q "$CONTRACT"
rc=$?
if [ "$rc" -eq 0 ]; then
	echo "PASS: native hook naming contract holds."
	exit 0
fi
if [ "$rc" -eq 1 ]; then
	echo "FAIL: native hook naming contract violated (a native hook was renamed/moved or an obsolete spelling returned)." >&2
	exit 1
fi
echo "BLOCKED: hook naming contract run did not complete (pytest exit $rc)." >&2
exit 2
