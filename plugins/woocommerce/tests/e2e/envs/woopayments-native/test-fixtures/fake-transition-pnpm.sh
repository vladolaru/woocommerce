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

if [[ "$*" == exec\ wp-env\ run\ * ]] &&
	[[ ! -f "$RUNTIME_STATE/wp-env" ]]; then
	echo 'Fake wp-env run refused a destroyed environment.' >&2
	exit 43
fi

if [[ "$*" == 'exec wp-env start' ]]; then
	assert_recovery_state_precedes_mutation
	node -e '
		const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
		require( "node:fs" ).writeFileSync(
			process.argv[ 2 ],
			String( state.wp_env_start_attempted === true )
		);
	' \
		"$WORKSPACE/resource-state.json" \
		"$RUNTIME_STATE/wp-env-intent-before-start"
	touch "$RUNTIME_STATE/wp-env"
	if [[ "${E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL:-0}" == '1' ]]; then
		echo 'Fake wp-env start failed after creating the isolated environment.' >&2
		exit 41
	fi
	exit 0
fi

if [[ "$*" == 'exec wp-env destroy' ]]; then
	if [[ "${E2E_FAKE_WP_ENV_DESTROY_FAIL:-0}" == '1' ]]; then
		echo 'Fake exact wp-env destroy failed.' >&2
		exit 42
	fi
	if [[ ! -f "$RUNTIME_STATE/wp-env" ]]; then
		printf 'true' > "$RUNTIME_STATE/wp-env-destroy-observed-absent"
	fi
	rm -f "$RUNTIME_STATE/wp-env"
	if [[ "${E2E_FAKE_KILL_AFTER_DELETE:-}" == 'wp-env' ]]; then
		kill -KILL "${E2E_TRANSITION_PROVISIONER_PID:?E2E_TRANSITION_PROVISIONER_PID is required}"
		exit 137
	fi
	exit 0
fi

if [[ "$*" == *'plugin get woocommerce-payments --field=version'* ]]; then
	printf '10.5.0\n'
	exit 0
fi

if [[ "$*" == *'transition_account_recovery_evidence'* ]]; then
	account_evidence_mode="${E2E_FAKE_ACCOUNT_EVIDENCE_MODE:-exact}"
	if [[ "${E2E_FAKE_ACCOUNT_MISMATCH:-0}" == '1' ]]; then
		account_evidence_mode='wrong-account'
	fi
	node -e '
		const { existsSync, readFileSync } = require( "node:fs" );
		const state = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		const mode = process.argv[ 3 ];
		const hasAccount = existsSync( process.argv[ 2 ] ) || mode === "preexisting";
		const status = {
			environment: "local",
			runtime: mode === "wrong-runtime" ? "native_core" : "extension",
			wcpay_active: true,
			connected: hasAccount,
			account_id: hasAccount ? "acct_transition_77" : "",
			account_type: hasAccount ? "test_drive" : "unknown",
			mode: "Dev",
			is_dev_mode: true,
			is_test_mode: true,
			is_live_mode: false,
			is_dev_environment: true,
			guardrail: {
				tier: hasAccount ? 0 : 1,
				allowed: hasAccount,
			},
		};
		let accountData = hasAccount
			? { account_id: "acct_transition_77", is_test_drive: true, is_live: false }
			: [];
		if ( mode === "ambiguous" ) {
			status.connected = true;
			status.account_id = "acct_transition_77";
			status.account_type = "test_drive";
			status.guardrail = { tier: 0, allowed: true };
			accountData = [
				{ account_id: "acct_transition_77", is_test_drive: true },
				{ account_id: "acct_transition_78", is_test_drive: true },
			];
		} else if ( mode === "live" ) {
			status.connected = true;
			status.account_id = "acct_live_owner";
			status.account_type = "live";
			status.is_dev_mode = false;
			status.is_test_mode = false;
			status.is_live_mode = true;
			status.guardrail = { tier: 2, allowed: false };
			accountData = { account_id: "acct_live_owner", is_live: true };
		} else if ( mode === "wrong-account" ) {
			status.connected = true;
			status.account_id = "acct_transition_77";
			status.account_type = "test_drive";
			status.guardrail = { tier: 0, allowed: true };
			accountData = { account_id: "acct_wrong_owner", is_test_drive: true, is_live: false };
		} else if ( mode === "guardrail-denied" ) {
			status.guardrail = { tier: 2, allowed: false };
		}
		process.stdout.write( JSON.stringify( {
			store: {
				site_url: state.base_url,
				home: state.home,
				marker: state.marker,
				wpcom_blog_id: state.wpcom_blog_id,
			},
			status,
			account_data: accountData,
		} ) );
	' \
		"$WORKSPACE/resource-state.json" \
		"$RUNTIME_STATE/account" \
		"$account_evidence_mode"
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
	account_create_mode="${E2E_FAKE_ACCOUNT_CREATE_MODE:-success}"
	if [[ "${E2E_FAKE_ACCOUNT_CREATE_FAIL:-0}" == '1' || "$account_create_mode" == 'before-create-failure' ]]; then
		echo 'Fake test-drive account creation failed.' >&2
		exit 1
	fi
	node -e '
		const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
		if ( state.account_creation_attempted !== true ) process.exit( 1 );
	' "$WORKSPACE/resource-state.json"
	touch "$RUNTIME_STATE/account"
	if [[ "$account_create_mode" == 'after-create-nonzero' ]]; then
		echo 'Fake test-drive account creation failed after allocation.' >&2
		exit 52
	fi
	if [[ "$account_create_mode" == 'after-create-kill' ]]; then
		kill -KILL "${E2E_TRANSITION_PROVISIONER_PID:?E2E_TRANSITION_PROVISIONER_PID is required}"
		exit 137
	fi
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
	if [[ "${E2E_FAKE_KILL_AFTER_DELETE:-}" == 'account' ]]; then
		kill -KILL "${E2E_TRANSITION_PROVISIONER_PID:?E2E_TRANSITION_PROVISIONER_PID is required}"
		exit 137
	fi
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
