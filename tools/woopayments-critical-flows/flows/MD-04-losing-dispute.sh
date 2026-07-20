#!/usr/bin/env bash
# MD-04 — submit deterministic losing evidence and observe a lost dispute.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/woopayments-critical-flows/lib/common.sh
source "$DIR/../lib/common.sh"
# shellcheck source=tools/woopayments-critical-flows/lib/md-resolution-shell-contract.sh
source "$DIR/../lib/md-resolution-shell-contract.sh"

MD_RESOLUTION_OUTCOME=lost
MD_RESOLUTION_FLOW_ID=MD-04-losing-dispute
export MD_RESOLUTION_OUTCOME MD_RESOLUTION_FLOW_ID
md_resolution_run
