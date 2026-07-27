#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
ORCHESTRATOR="$SCRIPT_DIR/run-saved-method-transition.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-orchestrator-test.XXXXXX")"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

run_orchestrator() {
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID='orchestrator-run' \
		E2E_TRANSITION_SEED_ARCHIVE="$TEST_ROOT/seed.tar.gz" \
		E2E_TRANSITION_SEED_MANIFEST="$TEST_ROOT/seed.json" \
		E2E_TRANSITION_STORE_PROVISIONER="$SCRIPT_DIR/test-fixtures/fake-transition-provisioner.sh" \
		E2E_TRANSITION_WRAPPER="$SCRIPT_DIR/test-fixtures/fake-transition-wrapper.sh" \
		E2E_TRANSITION_TEST_RUNNER="$SCRIPT_DIR/test-fixtures/fake-transition-test-runner.sh" \
		E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
		"$ORCHESTRATOR"
}

printf 'seed\n' > "$TEST_ROOT/seed.tar.gz"
printf '{}\n' > "$TEST_ROOT/seed.json"
run_orchestrator

grep -Fq -- '--project=woopayments-native-transition' "$TEST_ROOT/commands.log"
grep -Fq -- 'tests/woopayments-native/pilots/saved-method-cutover.spec.ts' "$TEST_ROOT/commands.log"
grep -Fq -- '--workers=1' "$TEST_ROOT/commands.log"
grep -Fq 'WCPAY_RUNTIME=transition' "$TEST_ROOT/commands.log"
grep -Fq 'E2E_WOOPAYMENTS_WPCOM_BLOG_ID=77' "$TEST_ROOT/commands.log"
grep -Fq 'E2E_WOOPAYMENTS_ACCOUNT_ID=acct_transition_77' "$TEST_ROOT/commands.log"
grep -Fq 'E2E_WOOPAYMENTS_ACCOUNT_ALIAS=reference-client' "$TEST_ROOT/commands.log"
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"
if grep -qi 'listen' "$TEST_ROOT/commands.log"; then
	echo 'The transition orchestrator must not start the Stripe listener.' >&2
	exit 1
fi

: > "$TEST_ROOT/commands.log"
if E2E_FAKE_TEST_RUNNER_FAIL=1 run_orchestrator; then
	echo 'The transition orchestrator hid the Playwright failure.' >&2
	exit 1
fi
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"

echo 'run-saved-method-transition.sh tests passed.'
