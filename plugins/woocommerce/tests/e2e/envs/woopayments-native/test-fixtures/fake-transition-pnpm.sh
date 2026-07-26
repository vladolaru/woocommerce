#!/usr/bin/env bash

set -euo pipefail

readonly WORKSPACE="${E2E_FAKE_TRANSITION_WORKSPACE:?E2E_FAKE_TRANSITION_WORKSPACE is required}"
readonly RUNTIME_STATE="${E2E_FAKE_RUNTIME_STATE:?E2E_FAKE_RUNTIME_STATE is required}"
readonly COMMAND_LOG="${E2E_FAKE_COMMAND_LOG:?E2E_FAKE_COMMAND_LOG is required}"

mode_of() {
	stat -f '%Lp' "$1" 2> /dev/null || stat -c '%a' "$1"
}

assert_recovery_state_precedes_mutation() {
	if [[ ! -f "$WORKSPACE/rollback-receipt" ]] ||
		[[ "$(mode_of "$WORKSPACE/rollback-receipt")" != '600' ]] ||
		[[ ! -f "$WORKSPACE/resource-state.json" ]] ||
		[[ "$(mode_of "$WORKSPACE/resource-state.json")" != '600' ]]; then
		echo 'Fake transition mutation observed no exact prewritten recovery state.' >&2
		exit 1
	fi
}

mkdir -p "$RUNTIME_STATE"
printf 'pnpm\t%s\t%s\t%s\n' "${WP_ENV_HOME:-unset}" "$PWD" "$*" >> "$COMMAND_LOG"

if [[ "$*" == 'exec wp-env start' ]]; then
	assert_recovery_state_precedes_mutation
	touch "$RUNTIME_STATE/wp-env"
	exit 0
fi

if [[ "$*" == 'exec wp-env destroy' ]]; then
	rm -f "$RUNTIME_STATE/wp-env"
	exit 0
fi

if [[ "$*" == *'plugin get woocommerce-payments --field=version'* ]]; then
	printf '10.5.0\n'
	exit 0
fi

if [[ "$*" == *'transition_identity_probe'* ]]; then
	node -e '
		const { readFileSync } = require( "node:fs" );
		const state = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		process.stdout.write( JSON.stringify( {
			site_url: state.base_url,
			home: state.home,
			marker: state.marker,
			wpcom_blog_id: state.wpcom_blog_id,
		} ) );
	' "$WORKSPACE/resource-state.json"
	exit 0
fi

if [[ "$*" == *'test-lab account create --type=test_drive --country=US --format=json'* ]]; then
	if [[ "${E2E_FAKE_ACCOUNT_CREATE_FAIL:-0}" == '1' ]]; then
		echo 'Fake test-drive account creation failed.' >&2
		exit 1
	fi
	touch "$RUNTIME_STATE/account"
	printf 'Creating test-drive account...\n{"success":true,"account_id":"acct_transition_77","is_test_drive":true}\nwp-env command completed\n'
	exit 0
fi

if [[ "$*" == *'test-lab account info --format=json'* ]]; then
	if [[ ! -f "$RUNTIME_STATE/account" ]]; then
		printf 'wp-env command starting\n{}\nwp-env command completed\n'
	elif [[ "${E2E_FAKE_ACCOUNT_MISMATCH:-0}" == '1' ]]; then
		printf 'wp-env command starting\n{"account_id":"acct_wrong_owner","is_test_drive":true}\nwp-env command completed\n'
	else
		printf 'wp-env command starting\n{"account_id":"acct_transition_77","is_test_drive":true}\nwp-env command completed\n'
	fi
	exit 0
fi

if [[ "$*" == *'test-lab account delete --format=json'* ]]; then
	rm -f "$RUNTIME_STATE/account"
	printf 'Deleting current test account...\n{"success":true,"deleted_account":"acct_transition_77"}\nwp-env command completed\n'
	exit 0
fi

if [[ "$*" == *'transition_prepare_store'* ]]; then
	printf 'prepared\n'
	exit 0
fi

case "$*" in
	*'plugin activate '*|*'wc tool run install_pages'*|*'option update '*|*'wcpay_dev local_wpcom_jetpack enable '*|*'wcpay_dev redirect_to '*|*'wcpay_dev set_blog_id '*)
		exit 0
		;;
esac

echo "Unexpected fake transition pnpm invocation: $*" >&2
exit 1
