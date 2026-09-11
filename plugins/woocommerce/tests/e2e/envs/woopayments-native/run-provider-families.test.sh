#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-provider-runner.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/delegate-bin" "$TEST_ROOT/store"
printf '{}\n' > "$TEST_ROOT/provider.json"
printf '{}\n' > "$TEST_ROOT/store/.wp-env.json"
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
if [[ -n "${E2E_FAKE_SETUP_FAILURE:-}" ]]; then
	exit 9
fi
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"

cat > "$TEST_ROOT/delegate-bin/pnpm" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\tpnpm %s\n' "$PWD" "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
if [[ "${1:-}" != 'test:e2e:with-env' || "${2:-}" != 'woopayments-native' ]]; then
	echo "Unexpected provider-runner entry point: $*" >&2
	exit 1
fi
shift 2
PATH="${E2E_ENV_SETUP_FAKE_BIN:?}:$PATH" "${E2E_REAL_ENV_SETUP:?}" "$@"
printf '  1 passed (1s)\n'
FAKE
chmod +x "$TEST_ROOT/delegate-bin/pnpm"

common=(
	--store-url 'http://native.test:8889'
	--native-store-dir "$TEST_ROOT/store"
	--account-id 'acct_ci'
	--account-alias 'native-ci'
	--store-id 'native-8889'
	--wpcom-blog-id '777'
	--wp-env-config "$TEST_ROOT/store/.wp-env.json"
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

for option in \
	--results-dir \
	--store-url \
	--native-store-dir \
	--account-id \
	--account-alias \
	--store-id \
	--wpcom-blog-id \
	--wp-env-config \
	--provider-fixture; do
	status="$(python3 - "$SCRIPT_DIR/run-provider-families.sh" "$option" <<'PY'
import subprocess
import sys

try:
    completed = subprocess.run(
        [sys.argv[1], sys.argv[2]],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=0.5,
        check=False,
    )
except subprocess.TimeoutExpired:
    print("timeout")
else:
    print(completed.returncode)
PY
)"
	if [[ "$status" != '2' ]]; then
		echo "$option without an operand must exit 2, got $status" >&2
		exit 1
	fi
done

status=0
PATH="$TEST_ROOT/bin:$PATH" E2E_FAKE_COMMAND_LOG="$TEST_ROOT/setup-failure.commands" \
	E2E_FAKE_SETUP_FAILURE=1 \
	"$SCRIPT_DIR/run-provider-families.sh" \
	--results-dir "$TEST_ROOT/setup-failure" "${common[@]}" \
	"$TEST_ROOT/basic.spec.ts" || status=$?
test "$status" = '9'
grep -q 'STOP basic rc=9' "$TEST_ROOT/setup-failure/families-status.txt"
if grep -q 'DONE rc=0' "$TEST_ROOT/setup-failure/families-status.txt"; then
	echo 'failed setup must retain its nonzero family verdict' >&2
	exit 1
fi

(
	cd "$TEST_ROOT"
	PATH="$TEST_ROOT/bin:$PATH" E2E_FAKE_COMMAND_LOG="$TEST_ROOT/relative.commands" \
		"$SCRIPT_DIR/run-provider-families.sh" \
		--results-dir relative-results \
		--store-url 'http://native.test:8889' \
		--native-store-dir store \
		--account-id 'acct_ci' \
		--account-alias 'native-ci' \
		--store-id 'native-8889' \
		--wpcom-blog-id '777' \
		--wp-env-config store/.wp-env.json \
		--provider-fixture provider.json \
		basic.spec.ts
)
grep -Fq "$(cd "$TEST_ROOT" && pwd -P)/basic.spec.ts" "$TEST_ROOT/relative.commands"

PATH="$TEST_ROOT/delegate-bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/delegated.commands" \
	E2E_ENV_SETUP_FAKE_BIN="$SCRIPT_DIR/test-fixtures/bin" \
	E2E_REAL_ENV_SETUP="$SCRIPT_DIR/env-setup.sh" \
	E2E_WPCOM_LOCAL_BIN="$SCRIPT_DIR/test-fixtures/bin/wpcom-local" \
	E2E_FAKE_LIST_TAGS='woopayments-provider' \
	"$SCRIPT_DIR/run-provider-families.sh" \
	--results-dir "$TEST_ROOT/delegated" \
	--store-url 'http://native.test:8889' \
	--native-store-dir "$TEST_ROOT/store" \
	--account-id 'acct_native' \
	--account-alias 'native-ci' \
	--store-id 'native-8889' \
	--wpcom-blog-id '2' \
	--wp-env-config "$TEST_ROOT/store/.wp-env.json" \
	--provider-fixture "$TEST_ROOT/provider.json" \
	"$TEST_ROOT/basic.spec.ts"
grep -Fq 'WooPayments callback readiness proved for native blog 2.' \
	"$TEST_ROOT/delegated/e2e-fidelity-basic.log"
readonly WP_ENV_CONFIG_ABS="$(cd "$TEST_ROOT/store" && pwd -P)/.wp-env.json"
grep -Fq "wp-env --config $WP_ENV_CONFIG_ABS run cli" \
	"$TEST_ROOT/delegated.commands"

echo 'run-provider-families.sh tests passed.'
