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
expected_store="$(
	cd "$WORKSPACE/store"
	pwd -P
)"
actual_store="$(
	cd "$PWD"
	pwd -P
)"
if [[ "$actual_store" != "$expected_store" ]]; then
	echo 'Fake wp-env was not invoked from the generated store.' >&2
	exit 44
fi
if [[ ! -f "$PWD/.wp-env.json" || -L "$PWD/.wp-env.json" ]]; then
	echo 'Fake wp-env observed no exact generated-store config.' >&2
	exit 44
fi
if ! node -e '
	const config = JSON.parse(
		require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" )
	);
	if (
		config.testsEnvironment !== false ||
		Object.hasOwn( config, "testsPort" )
	) process.exit( 1 );
' "$PWD/.wp-env.json"; then
	echo 'Fake wp-env observed a tests environment or tests port.' >&2
	exit 44
fi
if [[ -e "$PWD/package.json" ]]; then
	echo 'Fake wp-env observed a forbidden generated-store package manifest.' >&2
	exit 44
fi
if [[ "${WP_ENV_HOME:-}" != "$WORKSPACE/wp-env-home" ]]; then
	echo 'Fake wp-env was not invoked with the exact generated-store WP_ENV_HOME.' >&2
	exit 44
fi
printf 'wp-env\t%s\t%s\t%s\n' "$WP_ENV_HOME" "$PWD" "$*" >> "$COMMAND_LOG"

if [[ "$*" == run\ * ]] &&
	[[ ! -f "$RUNTIME_STATE/wp-env" ]]; then
	echo 'Fake wp-env run refused a destroyed environment.' >&2
	exit 43
fi

if [[ "$*" == 'start' ]]; then
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

if [[ "$*" == 'destroy --force' ]]; then
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

if [[ "$*" == *'transition_identity_probe'* ]]; then
	node -e '
		const { readFileSync } = require( "node:fs" );
		const state = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		process.stdout.write( JSON.stringify( {
			site_url: state.base_url,
			home: state.home,
			marker: state.marker,
			wpcom_blog_id: state.wpcom_blog_id,
			blog_token_present: true,
			user_token_present: true,
			account_id: state.account_id,
			is_live: false,
		} ) );
	' "$WORKSPACE/resource-state.json"
	exit 0
fi

if [[ "$*" == *'transition_inject_reference_fixture'* ]]; then
	node -e '
		const { readFileSync } = require( "node:fs" );
		const fixture = JSON.parse( readFileSync( 0, "utf8" ) );
		if (
			fixture.blog_id !== 77 ||
			fixture.blog_token !== "77.real-blog-token" ||
			fixture.user_token !== "77.real-user-token.1"
		) process.exit( 1 );
	'
	touch "$RUNTIME_STATE/reference-fixture-injected"
	exit 0
fi

if [[ "$*" == *'wcpay_dev refresh_account_data'* ]]; then
	touch "$RUNTIME_STATE/account"
	exit 0
fi

if [[ "$*" == *'transition_prepare_store'* ]]; then
	printf 'prepared\n'
	exit 0
fi

case "$*" in
	*'plugin activate '*|*'wc tool run install_pages'*|*'option update '*|*'option set '*|*'wcpay_dev local_wpcom_jetpack enable '*|*'wcpay_dev redirect_to '*|*'wcpay_dev set_blog_id '*)
		exit 0
		;;
esac

echo "Unexpected fake transition pnpm invocation: $*" >&2
exit 1
