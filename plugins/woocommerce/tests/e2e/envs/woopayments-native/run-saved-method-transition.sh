#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
readonly PLUGIN_ROOT="$(
	cd "$SCRIPT_DIR/../../../.."
	pwd -P
)"
readonly WRAPPER="${E2E_TRANSITION_WRAPPER:-$SCRIPT_DIR/provision-transition-store.sh}"
readonly REAL_PROVISIONER="${E2E_TRANSITION_STORE_PROVISIONER:-$SCRIPT_DIR/provision-transition-store-real.sh}"
readonly RUN_ID="${E2E_TRANSITION_RUN_ID:?E2E_TRANSITION_RUN_ID is required}"
readonly WPCOM_LOCAL_BIN="${E2E_WPCOM_LOCAL_BIN:-wpcom-local}"

allocation=''
teardown_started=0

teardown() {
	local primary_status=$?
	trap - EXIT INT TERM HUP
	if [[ -n "$allocation" && "$teardown_started" == '0' ]]; then
		teardown_started=1
		if ! E2E_TRANSITION_STORE_PROVISIONER="$REAL_PROVISIONER" \
			"$WRAPPER" destroy --allocation "$allocation"; then
			echo 'Exact transition teardown failed; allocation state remains for recovery.' >&2
			if (( primary_status == 0 )); then
				primary_status=1
			fi
		fi
	fi
	exit "$primary_status"
}

handle_signal() {
	local signal_number="$1"
	exit "$(( 128 + signal_number ))"
}

trap teardown EXIT
trap 'handle_signal 1' HUP
trap 'handle_signal 2' INT
trap 'handle_signal 15' TERM

allocation="$(
	E2E_TRANSITION_STORE_PROVISIONER="$REAL_PROVISIONER" \
		"$WRAPPER" create
)"

identity="$(
	node -e '
		const allocation = JSON.parse( process.argv[ 1 ] );
		if (
			typeof allocation.base_url !== "string" ||
			typeof allocation.store_id !== "string" ||
			! Number.isSafeInteger( allocation.wpcom_blog_id ) ||
			allocation.wpcom_blog_id <= 0 ||
			typeof allocation.account_id !== "string" ||
			! allocation.account_id
		) process.exit( 1 );
		process.stdout.write( [
			allocation.base_url,
			allocation.store_id,
			String( allocation.wpcom_blog_id ),
			allocation.account_id,
			allocation.workspace,
		].join( "\t" ) );
	' "$allocation"
)"
IFS=$'\t' read -r BASE_URL STORE_ID WPCOM_BLOG_ID ACCOUNT_ID WORKSPACE <<< "$identity"
readonly BASE_URL STORE_ID WPCOM_BLOG_ID ACCOUNT_ID WORKSPACE
readonly ACCOUNT_ALIAS="transition-${RUN_ID}"
readonly EXECUTION_SCOPE="$([[ -n "${CI:-}" ]] && printf ci || printf local)"

provider_approval="$(
	node -e '
		const approval = {
			schema_version: 1,
			approval_id: `transition-provisioner:${ process.argv[ 1 ] }`,
			execution_scope: process.argv[ 2 ],
			runtime: "transition",
			store_id: process.argv[ 3 ],
			site_url: process.argv[ 4 ],
			wpcom_blog_id: Number( process.argv[ 5 ] ),
			account_id: process.argv[ 6 ],
			account_alias: process.argv[ 7 ],
			test_mode: true,
			capabilities: [
				"saved-method-cutover",
				"plugin-owned-saved-card",
				"saved-card-default",
				"soft-cutover",
				"saved-card-state",
				"saved-card-classic",
				"saved-card-blocks",
				"product/payment",
			],
		};
		process.stdout.write( JSON.stringify( approval ) );
	' \
		"$RUN_ID" \
		"$EXECUTION_SCOPE" \
		"$STORE_ID" \
		"$BASE_URL" \
		"$WPCOM_BLOG_ID" \
		"$ACCOUNT_ID" \
		"$ACCOUNT_ALIAS"
)"
account_allocations="$(
	node -e '
		process.stdout.write( JSON.stringify( [ {
			storeId: process.argv[ 1 ],
			accountId: process.argv[ 2 ],
			accountAlias: process.argv[ 3 ],
		} ] ) );
	' "$STORE_ID" "$ACCOUNT_ID" "$ACCOUNT_ALIAS"
)"

export WCPAY_RUNTIME='transition'
export BASE_URL
export E2E_WOOPAYMENTS_SITE_URL="$BASE_URL"
export E2E_WOOPAYMENTS_STORE_ID="$STORE_ID"
export E2E_WOOPAYMENTS_WPCOM_BLOG_ID="$WPCOM_BLOG_ID"
export E2E_WOOPAYMENTS_ACCOUNT_ID="$ACCOUNT_ID"
export E2E_WOOPAYMENTS_ACCOUNT_ALIAS="$ACCOUNT_ALIAS"
export E2E_WOOPAYMENTS_ACCOUNT_ALLOCATIONS="$account_allocations"
export E2E_WOOPAYMENTS_PROVIDER_FIXTURE="$provider_approval"
export E2E_WOOPAYMENTS_LOCK_DIR="$WORKSPACE/locks"
export E2E_TRANSITION_ALLOCATION="$allocation"
export E2E_WPCOM_LOCAL_BIN="$WPCOM_LOCAL_BIN"
mkdir "$E2E_WOOPAYMENTS_LOCK_DIR"

if [[ "$EXECUTION_SCOPE" == 'ci' ]]; then
	export E2E_WOOPAYMENTS_CI_ACCOUNT_ALIAS="$ACCOUNT_ALIAS"
	export E2E_WOOPAYMENTS_CI_ACCOUNT_ID="$ACCOUNT_ID"
fi

readonly CONFIG='tests/e2e/envs/woopayments-native/playwright.config.ts'
readonly SPEC='tests/woopayments-native/pilots/saved-method-cutover.spec.ts'
if [[ -n "${E2E_TRANSITION_TEST_RUNNER:-}" ]]; then
	(
		cd "$PLUGIN_ROOT"
		"$E2E_TRANSITION_TEST_RUNNER" \
			"--config=$CONFIG" \
			'--project=woopayments-native-transition' \
			"$SPEC" \
			'--workers=1'
	)
else
	(
		cd "$PLUGIN_ROOT"
		pnpm exec playwright test \
			"--config=$CONFIG" \
			'--project=woopayments-native-transition' \
			"$SPEC" \
			'--workers=1'
	)
fi
