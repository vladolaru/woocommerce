#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-readonly-setup.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/store"

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf 'pnpm %s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
[[ "$*" == *'wp-env run cli wp --user=1 eval'* ]]
[[ "$*" == *'_wcpay_feature_customer_multi_currency'* ]]
[[ "$*" == *'wcpay_multi_currency_enabled_currencies'* ]]
[[ "$*" == *'express_checkout_checkout_methods'* ]]
grep -Fq '$settings["platform_checkout"] = "no"' <<< "$*"
[[ "$*" == *'upe_enabled_payment_method_ids'* ]]
[[ "$*" == *'"card", "klarna"'* ]]
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"

if PATH="$TEST_ROOT/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
	WCPAY_RUNTIME=client \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$TEST_ROOT/store" \
	"$SCRIPT_DIR/seed-readonly.sh" > "$TEST_ROOT/client.out" 2>&1; then
	echo 'readonly setup must reject a non-native runtime' >&2
	exit 1
fi

PATH="$TEST_ROOT/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
	WCPAY_RUNTIME=native \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$TEST_ROOT/store" \
	"$SCRIPT_DIR/seed-readonly.sh"

test "$(wc -l < "$TEST_ROOT/commands.log" | tr -d ' ')" = '1'

echo 'seed-readonly.sh tests passed.'
