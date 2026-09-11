#!/usr/bin/env bash

set -euo pipefail

readonly COMMAND_LOG="${E2E_FAKE_COMMAND_LOG:?E2E_FAKE_COMMAND_LOG is required}"

printf 'reference-wp\t%s\n' "$*" >> "$COMMAND_LOG"

if [[ "$*" != *'transition_reference_fixture'* ]]; then
	echo "Unexpected fake transition reference WP-CLI invocation: $*" >&2
	exit 1
fi

node -e '
	process.stdout.write( JSON.stringify( {
		blog_id: 77,
		blog_token: "77.real-blog-token",
		user_token: "77.real-user-token.1",
		account_id: "acct_transition_77",
		account_data: { account_id: "acct_transition_77", is_live: false, fixture_private: "private-account-payload" },
		is_live: false,
		local_wpcom_enabled: true,
		local_wpcom_base_url: "http://wpcom.localhost:8080",
		redirect_enabled: true,
		redirect_to: "http://wpcom.localhost:8080",
	} ) );
'
