#!/usr/bin/env bash

set -euo pipefail

if [[ "${1:-}" == '--version' ]]; then
	printf '%s\n' "${E2E_FAKE_NODE_VERSION:-v20.11.1}"
	exit 0
fi

exec "${E2E_FAKE_REAL_NODE_BIN:-node}" "$@"
