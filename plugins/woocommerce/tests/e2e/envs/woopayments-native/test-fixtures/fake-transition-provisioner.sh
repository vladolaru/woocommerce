#!/usr/bin/env bash

set -euo pipefail

case "${1:-}" in
	plan)
		printf '{"base_url":"%s","store_id":"%s","plugin_version":"%s"}\n' \
			"${E2E_FAKE_TRANSITION_BASE_URL:-http://transition.localhost:8899}" \
			"${E2E_FAKE_TRANSITION_STORE_ID:-transition-store-test}" \
			"${E2E_FAKE_TRANSITION_PLUGIN_VERSION:-10.5.0}"
		;;
	create)
		workspace=''
		shift
		while (( $# > 0 )); do
			case "$1" in
				--workspace)
					workspace="${2:-}"
					shift 2
					;;
				*)
					shift
					;;
			esac
		done
		if [[ "${E2E_FAKE_BLOCK_ALLOCATION_WRITE:-0}" == '1' ]]; then
			mkdir "$workspace/allocation.json"
		fi
		if [[ "${E2E_FAKE_CREATE_PARTIAL_FAILURE:-0}" == '1' ]]; then
			touch "$workspace/store-created"
			echo 'Fake transition create failed after creating the store.' >&2
			exit 1
		fi
		printf '{"base_url":"%s","store_id":"%s","plugin_version":"%s"}\n' \
			"${E2E_FAKE_CREATED_BASE_URL:-${E2E_FAKE_TRANSITION_BASE_URL:-http://transition.localhost:8899}}" \
			"${E2E_FAKE_CREATED_STORE_ID:-${E2E_FAKE_TRANSITION_STORE_ID:-transition-store-test}}" \
			"${E2E_FAKE_CREATED_PLUGIN_VERSION:-${E2E_FAKE_TRANSITION_PLUGIN_VERSION:-10.5.0}}"
		;;
	destroy)
		if [[ -z "${E2E_FAKE_PROVISIONER_LOG:-}" ]]; then
			echo 'E2E_FAKE_PROVISIONER_LOG is required.' >&2
			exit 1
			fi
			printf '%s\n' "$*" >> "$E2E_FAKE_PROVISIONER_LOG"
			if [[ "${E2E_FAKE_DESTROY_FAILURE:-0}" == '1' ]]; then
				echo 'Fake transition destroy failed.' >&2
				exit 1
			fi
			;;
	*)
		echo 'Expected create or destroy.' >&2
		exit 1
		;;
esac
