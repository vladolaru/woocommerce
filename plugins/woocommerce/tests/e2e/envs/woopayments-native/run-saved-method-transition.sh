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
readonly SCENARIO="${E2E_TRANSITION_SCENARIO:-saved-method}"
CTP_CAMPAIGN_ID=''
CTP_FALSE_DESTRUCTION_BOUNDARY=''

configure_ctp_campaign() {
	CTP_CAMPAIGN_ID="${E2E_TRANSITION_CTP_CAMPAIGN_ID:?E2E_TRANSITION_CTP_CAMPAIGN_ID is required for historical pay-for-order scenarios}"
	if [[ ! "$CTP_CAMPAIGN_ID" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ || "$CTP_CAMPAIGN_ID" == "$RUN_ID" ]]; then
		echo 'Historical pay-for-order requires a campaign ID distinct from the scenario run ID.' >&2
		return 1
	fi
	CTP_FALSE_DESTRUCTION_BOUNDARY="${TMPDIR:?TMPDIR is required}/woopayments-native-historical-pay-for-order-ctp-$CTP_CAMPAIGN_ID.json"
}

record_ctp_false_destruction() {
	local temporary_boundary="${CTP_FALSE_DESTRUCTION_BOUNDARY}.tmp.$RUN_ID"
	if ! node -e '
		const evidence = {
			schemaVersion: 1,
			campaignId: process.argv[ 1 ],
			runId: process.argv[ 2 ],
			scenario: "historical-pay-for-order-ctp-false",
		};
		process.stdout.write( JSON.stringify( evidence ) + "\n" );
	' "$CTP_CAMPAIGN_ID" "$RUN_ID" > "$temporary_boundary" || ! mv -f "$temporary_boundary" "$CTP_FALSE_DESTRUCTION_BOUNDARY"; then
		rm -f "$temporary_boundary"
		return 1
	fi
}

consume_ctp_false_destruction() {
	local claimed_boundary="${CTP_FALSE_DESTRUCTION_BOUNDARY}.consumed.$RUN_ID"
	if ! mv "$CTP_FALSE_DESTRUCTION_BOUNDARY" "$claimed_boundary" 2>/dev/null; then
		return 1
	fi
	if ! node -e '
		const { readFileSync } = require( "node:fs" );
		let evidence;
		try {
			evidence = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		} catch {
			process.exit( 1 );
		}
		const expectedKeys = [ "campaignId", "runId", "scenario", "schemaVersion" ];
		if (
			Object.keys( evidence ).sort().join( "," ) !== expectedKeys.sort().join( "," ) ||
			evidence.schemaVersion !== 1 ||
			evidence.campaignId !== process.argv[ 2 ] ||
			evidence.scenario !== "historical-pay-for-order-ctp-false" ||
			typeof evidence.runId !== "string" ||
			evidence.runId.length === 0 ||
			evidence.runId === process.argv[ 3 ]
		) {
			process.exit( 1 );
		}
	' "$claimed_boundary" "$CTP_CAMPAIGN_ID" "$RUN_ID"; then
		rm -f "$claimed_boundary"
		return 1
	fi
	if ! rm -f "$claimed_boundary"; then
		return 1
	fi
}

SPEC=''
GREP=''
CAPABILITIES=()
case "$SCENARIO" in
	saved-method)
		SPEC='tests/woopayments-native/pilots/saved-method-cutover.spec.ts'
		CAPABILITIES=(
			'saved-method-cutover'
			'plugin-owned-saved-card'
			'saved-card-default'
			'soft-cutover'
			'saved-card-state'
			'saved-card-cleanup'
			'saved-card-classic'
			'saved-card-blocks'
			'product/payment'
		)
		;;
	historical-tokens)
		SPEC='tests/woopayments-native/transitions/historical-tokens.spec.ts'
		CAPABILITIES=(
			'historical-tokens'
			'plugin-owned-saved-card'
			'saved-card-default'
			'soft-cutover'
			'saved-card-state'
			'saved-card-cleanup'
			'saved-card-classic'
			'product/payment'
		)
		;;
	cutover-reconciliation)
		SPEC='tests/woopayments-native/transitions/cutover-reconciliation.spec.ts'
		CAPABILITIES=(
			'cutover-reconciliation'
			'cutover-ui'
			'cutover-job'
			'native-owner'
			'basic-card'
			'basic-card-entry'
			'product/payment'
		)
		;;
	cutover-network-reconciliation)
		SPEC='tests/woopayments-native/transitions/cutover-network-reconciliation.spec.ts'
		CAPABILITIES=(
			'cutover-network-reconciliation'
			'cutover-reconciliation'
			'cutover-ui'
			'cutover-job'
			'native-owner'
			'basic-card'
		)
		;;
	historical-pay-for-order-ctp-false)
		if ! configure_ctp_campaign || ! rm -f "$CTP_FALSE_DESTRUCTION_BOUNDARY"; then
			echo 'The disabled historical pay-for-order scenario could not invalidate its prior campaign boundary.' >&2
			exit 65
		fi
		SPEC='tests/woopayments-native/transitions/historical-money-records.spec.ts'
		GREP='plugin-origin failed order pays in place after cutover with card-testing protection disabled'
		CAPABILITIES=(
			'historical-pay-for-order-ctp-false'
			'card-decline-checkout'
			'card-decline-customer-state'
			'card-decline-setup-intent'
			'card-testing-protection-setting'
			'classic-checkout-page'
			'soft-cutover'
			'basic-card'
			'basic-card-entry'
			'product/payment'
		)
		;;
	historical-pay-for-order-ctp-true)
		if ! configure_ctp_campaign || ! consume_ctp_false_destruction; then
			echo 'The enabled historical pay-for-order scenario requires valid unconsumed disabled teardown evidence for this campaign.' >&2
			exit 65
		fi
		SPEC='tests/woopayments-native/transitions/historical-money-records.spec.ts'
		GREP='plugin-origin failed order pays in place after cutover with card-testing protection enabled'
		CAPABILITIES=(
			'historical-pay-for-order-ctp-true'
			'card-decline-checkout'
			'card-decline-customer-state'
			'card-decline-setup-intent'
			'card-testing-protection-setting'
			'classic-checkout-page'
			'soft-cutover'
			'basic-card'
			'basic-card-entry'
			'product/payment'
		)
		;;
	*)
		echo "Unknown transition scenario: $SCENARIO" >&2
		exit 64
		;;
esac
readonly SPEC
readonly GREP
readonly -a CAPABILITIES
readonly CTP_CAMPAIGN_ID
readonly CTP_FALSE_DESTRUCTION_BOUNDARY

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
		elif [[ "$SCENARIO" == 'historical-pay-for-order-ctp-false' && "$primary_status" == '0' ]]; then
			if ! record_ctp_false_destruction; then
				echo 'Disabled historical pay-for-order teardown boundary could not be recorded.' >&2
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
unset E2E_TRANSITION_WCS_ARCHIVE E2E_TRANSITION_WCS_MANIFEST

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
readonly ACCOUNT_ALIAS='reference-client'
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
			capabilities: process.argv.slice( 8 ),
		};
		process.stdout.write( JSON.stringify( approval ) );
	' \
		"$RUN_ID" \
		"$EXECUTION_SCOPE" \
		"$STORE_ID" \
		"$BASE_URL" \
		"$WPCOM_BLOG_ID" \
		"$ACCOUNT_ID" \
		"$ACCOUNT_ALIAS" \
		"${CAPABILITIES[@]}"
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
export E2E_WOOPAYMENTS_LOCK_DIR="${E2E_WOOPAYMENTS_LOCK_DIR:-${TMPDIR:?TMPDIR is required}/woopayments-native-resource-locks}"
export E2E_TRANSITION_ALLOCATION="$allocation"
export E2E_TRANSITION_SEED_PROFILE="${E2E_TRANSITION_SEED_PROFILE:-10.5.0}"
export E2E_TRANSITION_PENDING_MIGRATOR_HOOK="${E2E_TRANSITION_PENDING_MIGRATOR_HOOK:-0}"
export E2E_TRANSITION_SCENARIO="$SCENARIO"
mkdir -p "$E2E_WOOPAYMENTS_LOCK_DIR"

if [[ "$EXECUTION_SCOPE" == 'ci' ]]; then
	export E2E_WOOPAYMENTS_CI_ACCOUNT_ALIAS="$ACCOUNT_ALIAS"
	export E2E_WOOPAYMENTS_CI_ACCOUNT_ID="$ACCOUNT_ID"
fi

readonly CONFIG='tests/e2e/envs/woopayments-native/playwright.config.ts'
PLAYWRIGHT_ARGUMENTS=(
	"--config=$CONFIG"
	'--project=woopayments-native-transition'
	"$SPEC"
	'--workers=1'
)
if [[ -n "$GREP" ]]; then
	PLAYWRIGHT_ARGUMENTS+=( "--grep=$GREP" '--retries=0' )
fi
readonly -a PLAYWRIGHT_ARGUMENTS
if [[ -n "${E2E_TRANSITION_TEST_RUNNER:-}" ]]; then
	(
		cd "$PLUGIN_ROOT"
		"$E2E_TRANSITION_TEST_RUNNER" "${PLAYWRIGHT_ARGUMENTS[@]}"
	)
else
	(
		cd "$PLUGIN_ROOT"
		pnpm exec playwright test "${PLAYWRIGHT_ARGUMENTS[@]}"
	)
fi
