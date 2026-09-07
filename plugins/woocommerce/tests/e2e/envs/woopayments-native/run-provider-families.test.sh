#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-provider-runner.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/store"
printf '{}\n' > "$TEST_ROOT/provider.json"
touch "$TEST_ROOT/basic.spec.ts" "$TEST_ROOT/refunds.spec.ts"

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
printf '  1 passed (1s)\n'
if [[ -n "${E2E_FAKE_RESIDUE:-}" ]]; then
	mkdir -p "$E2E_WOOPAYMENTS_LOCK_DIR/provider-write-attempts"
	touch "$E2E_WOOPAYMENTS_LOCK_DIR/provider-write-attempts/unresolved.json"
fi
if [[ -n "${E2E_FAKE_FAIL_PATTERN:-}" && "$*" == *"$E2E_FAKE_FAIL_PATTERN"* ]]; then
	exit 7
fi
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"

common=(
	--store-url 'http://native.test:8889'
	--native-store-dir "$TEST_ROOT/store"
	--account-id 'acct_ci'
	--account-alias 'native-ci'
	--store-id 'native-8889'
	--wpcom-blog-id '777'
	--provider-fixture "$TEST_ROOT/provider.json"
)

PATH="$TEST_ROOT/bin:$PATH" E2E_FAKE_COMMAND_LOG="$TEST_ROOT/success.commands" \
	"$SCRIPT_DIR/run-provider-families.sh" \
	--results-dir "$TEST_ROOT/success" "${common[@]}" \
	"$TEST_ROOT/basic.spec.ts" "$TEST_ROOT/refunds.spec.ts"

test "$(wc -l < "$TEST_ROOT/success.commands" | tr -d ' ')" = '2'
grep -q 'DONE rc=0' "$TEST_ROOT/success/families-status.txt"

status=0
PATH="$TEST_ROOT/bin:$PATH" E2E_FAKE_COMMAND_LOG="$TEST_ROOT/failure.commands" \
	E2E_FAKE_FAIL_PATTERN='refunds.spec.ts' \
	"$SCRIPT_DIR/run-provider-families.sh" \
	--results-dir "$TEST_ROOT/failure" "${common[@]}" \
	"$TEST_ROOT/basic.spec.ts" "$TEST_ROOT/refunds.spec.ts" || status=$?
test "$status" = '7'
grep -q 'STOP refunds rc=7' "$TEST_ROOT/failure/families-status.txt"
if grep -q 'DONE rc=0' "$TEST_ROOT/failure/families-status.txt"; then
	echo 'failed family must not be rewritten as green' >&2
	exit 1
fi

status=0
PATH="$TEST_ROOT/bin:$PATH" E2E_FAKE_COMMAND_LOG="$TEST_ROOT/residue.commands" \
	E2E_FAKE_RESIDUE=1 \
	"$SCRIPT_DIR/run-provider-families.sh" \
	--results-dir "$TEST_ROOT/residue" "${common[@]}" \
	"$TEST_ROOT/basic.spec.ts" || status=$?
test "$status" = '1'
grep -q 'STOP quarantine/write-attempt residue' "$TEST_ROOT/residue/families-status.txt"

echo 'run-provider-families.sh tests passed.'
