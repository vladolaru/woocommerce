#!/usr/bin/env bash

set -euo pipefail

case "${1:-}" in
	plan|create)
		printf '{"base_url":"%s","store_id":"%s","plugin_version":"%s"}\n' \
			"${E2E_FAKE_TRANSITION_BASE_URL:-http://transition.localhost:8899}" \
			"${E2E_FAKE_TRANSITION_STORE_ID:-transition-store-test}" \
			"${E2E_FAKE_TRANSITION_PLUGIN_VERSION:-10.5.0}"
		;;
	destroy)
		if [[ -z "${E2E_FAKE_PROVISIONER_LOG:-}" ]]; then
			echo 'E2E_FAKE_PROVISIONER_LOG is required.' >&2
			exit 1
		fi
		printf '%s\n' "$*" >> "$E2E_FAKE_PROVISIONER_LOG"
		;;
	*)
		echo 'Expected create or destroy.' >&2
		exit 1
		;;
esac
