#!/usr/bin/env bash
# MD-03 — submit deterministic winning evidence and observe a won dispute.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/woopayments-critical-flows/lib/common.sh
source "$DIR/../lib/common.sh"
# shellcheck source=tools/woopayments-critical-flows/lib/md-resolution-shell-contract.sh
source "$DIR/../lib/md-resolution-shell-contract.sh"

MD_RESOLUTION_OUTCOME=won
MD_RESOLUTION_FLOW_ID=MD-03-winning-dispute
export MD_RESOLUTION_OUTCOME MD_RESOLUTION_FLOW_ID
md_resolution_run
