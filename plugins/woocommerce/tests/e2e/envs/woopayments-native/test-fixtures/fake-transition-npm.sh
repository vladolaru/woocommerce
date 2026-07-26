#!/usr/bin/env bash

set -euo pipefail

readonly COMMAND_LOG="${E2E_FAKE_COMMAND_LOG:?E2E_FAKE_COMMAND_LOG is required}"
printf 'npm %s\n' "$*" >> "$COMMAND_LOG"

case "$*" in
	'ci --ignore-scripts --no-audit --no-fund')
		if [[ ! -f package-lock.json || -e node_modules ]]; then
			echo 'Fake npm ci requires a clean extracted lockfile tree.' >&2
			exit 1
		fi
		mkdir node_modules
		printf 'locked fake webpack\n' > node_modules/fake-webpack
		;;
	'run --ignore-scripts build:client')
		if [[ ! -f node_modules/fake-webpack || -e dist ]]; then
			echo 'Fake npm build requires its exact clean install boundary.' >&2
			exit 1
		fi
		mkdir dist
		printf 'generated index JavaScript\n' > dist/index.js
		printf 'generated index CSS\n' > dist/index.css
		printf 'generated checkout JavaScript\n' > dist/checkout.js
		if [[ "${E2E_FAKE_NPM_MODE:-complete}" != 'partial' ]]; then
			printf 'generated blocks checkout JavaScript\n' > dist/blocks-checkout.js
		fi
		;;
	*)
		echo "Unexpected fake npm command: $*" >&2
		exit 1
		;;
esac

printf 'fake-npm-output-with-secret=%s\n' "${E2E_FAKE_NPM_SECRET:-unset}"
