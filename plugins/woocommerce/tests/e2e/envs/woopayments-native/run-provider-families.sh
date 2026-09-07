#!/usr/bin/env bash

set -euo pipefail

usage() {
	echo 'Usage: run-provider-families.sh --results-dir DIR --store-url URL --native-store-dir DIR --account-id ID --account-alias ALIAS --store-id ID --wpcom-blog-id ID --provider-fixture JSON SPEC...' >&2
	exit 2
}

results_dir=''
store_url=''
native_store_dir=''
account_id=''
account_alias=''
store_id=''
wpcom_blog_id=''
provider_fixture=''

while [[ $# -gt 0 ]]; do
	case "$1" in
		--results-dir) [[ $# -ge 2 ]] || usage; results_dir="$2"; shift 2 ;;
		--store-url) [[ $# -ge 2 ]] || usage; store_url="$2"; shift 2 ;;
		--native-store-dir) [[ $# -ge 2 ]] || usage; native_store_dir="$2"; shift 2 ;;
		--account-id) [[ $# -ge 2 ]] || usage; account_id="$2"; shift 2 ;;
		--account-alias) [[ $# -ge 2 ]] || usage; account_alias="$2"; shift 2 ;;
		--store-id) [[ $# -ge 2 ]] || usage; store_id="$2"; shift 2 ;;
		--wpcom-blog-id) [[ $# -ge 2 ]] || usage; wpcom_blog_id="$2"; shift 2 ;;
		--provider-fixture) [[ $# -ge 2 ]] || usage; provider_fixture="$2"; shift 2 ;;
		--) shift; break ;;
		-*) usage ;;
		*) break ;;
	esac
done

[[ -n "$results_dir" && -n "$store_url" && -n "$native_store_dir" && -n "$account_id" && -n "$account_alias" && -n "$store_id" && -n "$wpcom_blog_id" && -n "$provider_fixture" && $# -gt 0 ]] || usage
[[ "$store_url" =~ ^https?://[^[:space:]]+$ ]] || usage
[[ "$wpcom_blog_id" =~ ^[1-9][0-9]*$ ]] || usage
[[ -d "$native_store_dir" && -f "$provider_fixture" ]] || usage
jq -e 'type == "object"' "$provider_fixture" > /dev/null || usage

specs=()
for spec in "$@"; do
	[[ -f "$spec" && "$spec" == *.spec.ts ]] || usage
	specs+=( "$(cd "$(dirname "$spec")" && pwd -P)/$(basename "$spec")" )
done

mkdir -p "$results_dir"
results_dir="$(cd "$results_dir" && pwd -P)"
native_store_dir="$(cd "$native_store_dir" && pwd -P)"
provider_fixture="$(cd "$(dirname "$provider_fixture")" && pwd -P)/$(basename "$provider_fixture")"

export WCPAY_RUNTIME=native
export E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$native_store_dir"
export E2E_WOOPAYMENTS_NATIVE_STORE_URL="$store_url"
export E2E_WOOPAYMENTS_SITE_URL="$store_url"
export BASE_URL="$store_url"
export WP_BASE_URL="$store_url"
export E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$results_dir/e2e-diag"
export E2E_WOOPAYMENTS_LOCK_DIR="$results_dir/e2e-locks"
export E2E_WOOPAYMENTS_STORE_ID="$store_id"
export E2E_WOOPAYMENTS_ACCOUNT_ID="$account_id"
export E2E_WOOPAYMENTS_ACCOUNT_ALIAS="$account_alias"
export E2E_WOOPAYMENTS_WPCOM_BLOG_ID="$wpcom_blog_id"
export E2E_WOOPAYMENTS_ACCOUNT_ALLOCATIONS="$(jq -cn --arg store "$store_id" --arg account "$account_id" --arg alias "$account_alias" '[{storeId:$store,accountId:$account,accountAlias:$alias}]')"
export E2E_WOOPAYMENTS_PROVIDER_FIXTURE="$(jq -c . "$provider_fixture")"

readonly status_file="$results_dir/families-status.txt"
: > "$status_file"

count_residue() {
	local directory="$1"
	if [[ ! -d "$directory" ]]; then
		printf '0'
		return
	fi
	find "$directory" -type f | wc -l | tr -d ' '
}

for spec in "${specs[@]}"; do
	name="$(basename "$spec" .spec.ts | sed 's/^provider-fidelity-//')"
	log_file="$results_dir/e2e-fidelity-$name.log"
	printf '%s START %s\n' "$(date '+%H:%M:%S')" "$name" >> "$status_file"
	if (
		cd "$native_store_dir"
		pnpm test:e2e:with-env woopayments-native \
			--project=woopayments-native-provider "$spec"
	) > "$log_file" 2>&1; then
		rc=0
	else
		rc=$?
	fi
	summary="$(grep -E '^\s+[0-9]+ (passed|failed|skipped|flaky|did not run)' "$log_file" | tr -s ' \n' ' ' || true)"
	quarantine_count="$(count_residue "$E2E_WOOPAYMENTS_LOCK_DIR/quarantine")"
	attempt_count="$(count_residue "$E2E_WOOPAYMENTS_LOCK_DIR/provider-write-attempts")"
	printf '%s END %s rc=%s %s q=%s a=%s\n' "$(date '+%H:%M:%S')" "$name" "$rc" "$summary" "$quarantine_count" "$attempt_count" >> "$status_file"
	if [[ "$rc" -ne 0 ]]; then
		printf '%s STOP %s rc=%s\n' "$(date '+%H:%M:%S')" "$name" "$rc" >> "$status_file"
		exit "$rc"
	fi
	if [[ "$quarantine_count" != '0' || "$attempt_count" != '0' ]]; then
		printf '%s STOP quarantine/write-attempt residue after %s\n' "$(date '+%H:%M:%S')" "$name" >> "$status_file"
		exit 1
	fi
done

printf '%s DONE rc=0\n' "$(date '+%H:%M:%S')" >> "$status_file"
