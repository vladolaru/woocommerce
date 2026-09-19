#!/usr/bin/env bash

set -euo pipefail

readonly COMMAND_LOG="${E2E_FAKE_COMMAND_LOG:?E2E_FAKE_COMMAND_LOG is required}"
printf 'pnpm %s\n' "$*" >> "$COMMAND_LOG"

case "$*" in
	'--version')
		printf '%s\n' "${E2E_FAKE_PNPM_VERSION:-11.13.1}"
		exit 0
		;;
	'install --frozen-lockfile --ignore-scripts')
		if [[ ! -f pnpm-lock.yaml || -e package-lock.json || -e node_modules ]]; then
			echo 'Fake pnpm install requires a clean extracted pnpm lockfile tree.' >&2
			exit 1
		fi
		mkdir node_modules
		printf 'locked fake webpack\n' > node_modules/fake-webpack
		;;
	'run build:client')
		if [[ ! -f node_modules/fake-webpack || -e dist ]]; then
			echo 'Fake pnpm build requires its exact clean install boundary.' >&2
			exit 1
		fi
		mkdir dist
		printf 'generated index JavaScript\n' > dist/index.js
		printf 'generated index CSS\n' > dist/index.css
		printf 'generated checkout JavaScript\n' > dist/checkout.js
		printf 'generated blocks checkout JavaScript\n' > dist/blocks-checkout.js
		if [[ -n "${E2E_FAKE_GENERATED_MTIME:-}" ]]; then
			find dist -exec touch -t "$E2E_FAKE_GENERATED_MTIME" {} +
		fi
		;;
	*)
		echo "Unexpected fake pnpm command: $*" >&2
		exit 1
		;;
esac

printf 'fake-pnpm-output-with-secret=%s\n' "${E2E_FAKE_PNPM_SECRET:-unset}"
