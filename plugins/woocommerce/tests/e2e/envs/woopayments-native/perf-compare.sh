#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly REFERENCE_DIRECTORY='woocommerce-payments-reference'
readonly REFERENCE_SHA256='9b80d9002e831f62d72bba09dec8b09ca3dc8116c637bae94c74160063767cc9'
readonly REFERENCE_URL='https://github.com/Automattic/woocommerce-payments/releases/download/10.8.0/woocommerce-payments.zip'
readonly PROBE_SOURCE='wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native/perf-probe.php'
readonly PROBE_TARGET='wp-content/mu-plugins/woopayments-native-perf-probe.php'
readonly CLI_HELPER_MARKER='woocommerce-native-perf-helper'

MODE='local'
STORE_URL='http://localhost:8187'
REQUEST_BASE=''
OUTPUT=''
WP_ENV_CONFIG='.wp-env.json'
WP_ENV_SERVICE=''
TEMP_ROOT=''
CLEANED=0
CLEANUP_FAILED=0
DATABASE_EXPORTED=0
DATABASE_SNAPSHOT=''
ACCOUNT_FETCHED_AT=''
PRODUCT_PATH=''
PRODUCT_ID=''
FRONT_PATH=''
SHOP_PATH=''
CART_PATH=''
CHECKOUT_PATH=''
CART_API_PATH=''
GATE_FAILED=0
SAMPLE_FAILED=0

usage() {
	echo 'Usage: perf-compare.sh [--mode local|ci] [--store-url URL] --output FILE [--wp-env-config FILE] [--wp-env-service cli|tests-cli]' >&2
}

die_usage() {
	echo "$1" >&2
	usage
	exit 2
}

store_wp() {
	(
		cd "$PLUGIN_ROOT"
		pnpm exec wp-env --config "$WP_ENV_CONFIG" run "$WP_ENV_SERVICE" wp "$@"
	)
}

store_cli() {
	(
		cd "$PLUGIN_ROOT"
		pnpm exec wp-env --config "$WP_ENV_CONFIG" run "$WP_ENV_SERVICE" "$@"
	)
}

store_helper() {
	store_wp eval-file "$PROBE_SOURCE" --use-include "$CLI_HELPER_MARKER" "$@"
}

parse_arguments() {
	while (($#)); do
		case "$1" in
			--mode)
				[[ $# -ge 2 ]] || die_usage '--mode requires an operand'
				MODE="$2"
				shift 2
				;;
			--store-url)
				[[ $# -ge 2 ]] || die_usage '--store-url requires an operand'
				STORE_URL="$2"
				shift 2
				;;
			--output)
				[[ $# -ge 2 ]] || die_usage '--output requires an operand'
				OUTPUT="$2"
				shift 2
				;;
			--wp-env-config)
				[[ $# -ge 2 ]] || die_usage '--wp-env-config requires an operand'
				WP_ENV_CONFIG="$2"
				shift 2
				;;
			--wp-env-service)
				[[ $# -ge 2 ]] || die_usage '--wp-env-service requires an operand'
				WP_ENV_SERVICE="$2"
				shift 2
				;;
			*) die_usage "Unsupported argument: $1" ;;
		esac
	done
	[[ "$MODE" == 'local' || "$MODE" == 'ci' ]] || die_usage "Unsupported mode: $MODE"
	if [[ -z "$WP_ENV_SERVICE" ]]; then
		if [[ "$MODE" == local ]]; then WP_ENV_SERVICE='tests-cli'; else WP_ENV_SERVICE='cli'; fi
	fi
	[[ "$WP_ENV_SERVICE" == 'cli' || "$WP_ENV_SERVICE" == 'tests-cli' ]] || die_usage "Unsupported wp-env service: $WP_ENV_SERVICE"
	[[ -n "$OUTPUT" ]] || die_usage '--output is required'
	[[ -d "$(dirname "$OUTPUT")" ]] || die_usage 'The --output parent directory must exist'
}

set_option_json() {
	store_wp option update "$1" "$2" --format=json > /dev/null
}

canonical_is_active() {
	store_wp plugin is-active woocommerce-payments > /dev/null 2>&1
}

discover_request_base() {
	local home_url
	home_url="$(store_wp option get home)" || return 1
	[[ "$home_url" =~ ^https?://[^/?#[:space:]]+(/[^?#[:space:]]*)?$ ]] || return 1
	REQUEST_BASE="$(printf '%s\n' "$home_url" | sed -E 's#^(https?://[^/]+).*$#\1#')"
	[[ "$REQUEST_BASE" =~ ^https?://[^/?#[:space:]]+$ ]]
}

install_reference() {
	local version
	if ! store_helper install-reference "$REFERENCE_URL" "$REFERENCE_SHA256" "$REFERENCE_DIRECTORY" > /dev/null; then return 1; fi
	if ! version="$(store_wp plugin get woocommerce-payments-reference --field=version)"; then return 1; fi
	[[ "$version" == '10.8.0' ]] || { echo 'The reference plugin is not WooPayments 10.8.0.' >&2; return 1; }
}

cleanup() {
	local failed=0
	if [[ "$CLEANED" == 1 ]]; then return "$CLEANUP_FAILED"; fi
	CLEANED=1
	if [[ "$DATABASE_EXPORTED" == 1 ]] && ! store_wp db import "$DATABASE_SNAPSHOT" > /dev/null; then
		echo 'Cleanup failed to restore the disposable database.' >&2
		failed=1
	fi
	if ! store_cli rm -f "$PROBE_TARGET" > /dev/null 2>&1; then echo 'Cleanup failed to remove the MU probe.' >&2; failed=1; fi
	if ! store_cli rm -rf wp-content/plugins/woocommerce-payments-reference wp-content/plugins/woocommerce-payments-reference-stage > /dev/null 2>&1; then echo 'Cleanup failed to remove the reference plugin.' >&2; failed=1; fi
	if [[ -n "$DATABASE_SNAPSHOT" ]] && ! store_cli rm -f "$DATABASE_SNAPSHOT" > /dev/null 2>&1; then echo 'Cleanup failed to remove the database snapshot.' >&2; failed=1; fi
	if [[ -n "$TEMP_ROOT" ]] && ! rm -rf "$TEMP_ROOT"; then echo 'Cleanup failed to remove its temporary directory.' >&2; failed=1; fi
	CLEANUP_FAILED=$failed
	return "$failed"
}

on_exit() {
	local command_status=$?
	local cleanup_status=0
	trap - EXIT
	cleanup || cleanup_status=$?
	if [[ $cleanup_status -ne 0 ]]; then
		echo 'Cleanup failed; the performance comparison is invalid.' >&2
		command_status=1
	fi
	exit "$command_status"
}

seed_and_export_database() {
	local product
	WCPAY_RUNTIME=native E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$PLUGIN_ROOT" E2E_WOOPAYMENTS_WP_ENV_CONFIG="$WP_ENV_CONFIG" E2E_WOOPAYMENTS_WP_ENV_SERVICE="$WP_ENV_SERVICE" "$SCRIPT_DIR/seed-readonly.sh" || return 1
	ACCOUNT_FETCHED_AT="$(date +%s)" || return 1
	[[ "$ACCOUNT_FETCHED_AT" =~ ^[1-9][0-9]*$ ]] || return 1
	store_wp option update woocommerce_coming_soon no > /dev/null || return 1
	product="$(store_helper ensure-product)" || return 1
	IFS=$'\t' read -r PRODUCT_ID FRONT_PATH SHOP_PATH PRODUCT_PATH CART_PATH CHECKOUT_PATH CART_API_PATH <<EOF
$product
EOF
	[[ "$PRODUCT_ID" =~ ^[0-9]+$ ]] || return 1
	for product in "$FRONT_PATH" "$SHOP_PATH" "$PRODUCT_PATH" "$CART_PATH" "$CHECKOUT_PATH" "$CART_API_PATH"; do
		[[ "$product" == /* ]] || return 1
	done
	store_wp db export "$DATABASE_SNAPSHOT" > /dev/null || return 1
	DATABASE_EXPORTED=1
}

reset_database() {
	store_wp db import "$DATABASE_SNAPSHOT" > /dev/null
}

install_probe_and_reference() {
	if [[ "$MODE" == local ]] && ! install_reference; then
		echo 'The reference plugin failed integrity or installation checks.' >&2
		return 1
	fi
	store_cli cp "$PROBE_SOURCE" "$PROBE_TARGET" > /dev/null || return 1
}

prepare_state() {
	local state="$1"
	local native_state='disabled'
	local account='{}'
	local settings='{"enabled":"no","test_mode":"yes","platform_checkout":"no"}'
	local control='{"state":"'"$state"'","reference_plugin_slug":"woocommerce-payments-reference/woocommerce-payments.php"}'
	case "$state" in
	baseline_noop|disabled) native_state='disabled' ;;
	available) native_state='available' ;;
	connected) native_state='connected' ;;
	active_native|active_plugin) native_state='active' ;;
	*) echo "Unsupported performance state: $state" >&2; return 1 ;;
	esac
	if [[ "$state" == 'connected' || "$state" == 'active_native' || "$state" == 'active_plugin' ]]; then
		account='{"data":{"account_id":"acct_native_ci","country":"US","default_currency":"usd","payments_enabled":true,"payouts_enabled":true,"details_submitted":true,"is_live":false,"test_publishable_key":"pk_test_native_ci","live_publishable_key":"","statement_descriptor":"NATIVE CI","statement_descriptor_kanji":"","statement_descriptor_kana":"","business_profile":{"name":"Native CI store","url":"https://example.test","support_address":{"country":"US"},"support_email":"support@example.test","support_phone":"+10000000000"},"branding":{"logo":"","icon":"","primary_color":"#000000","secondary_color":"#ffffff"},"communications_email":"owner@example.test","store_currencies":{"default":"usd"},"customer_currencies":{"supported":["usd","eur","aud","cad","chf","gbp","jpy","nzd","sek"]},"account_details":{"account_status":{"text":"Enabled"},"payout_status":{"text":"Enabled"},"banner":null},"deposits":{"interval":"daily","weekly_anchor":"monday","monthly_anchor":1,"delay_days":2,"status":"enabled","restrictions":"","completed_waiting_period":true},"platform_checkout_eligible":true,"capabilities":{"card_payments":"active","klarna_payments":"active"},"supported_payment_methods":["card","klarna"],"fees":{"card":[],"klarna":[]}},"fetched":'"$ACCOUNT_FETCHED_AT"',"errored":false,"consecutive_errors":0}'
		if [[ "$state" == 'active_native' || "$state" == 'active_plugin' ]]; then settings='{"enabled":"yes","test_mode":"yes","platform_checkout":"no","upe_enabled_payment_method_ids":["card"]}'; fi
	fi
	set_option_json 'woocommerce_native_payments_perf_probe_control' "$control" || return 1
	set_option_json 'woocommerce_native_payments_state' '"'"$native_state"'"' || return 1
	set_option_json 'woocommerce_native_payments_killswitch' 'false' || return 1
	set_option_json '_wcpay_feature_customer_multi_currency' '"0"' || return 1
	set_option_json 'wcpay_account_data' "$account" || return 1
	set_option_json 'woocommerce_woocommerce_payments_settings' "$settings" || return 1
	if [[ "$MODE" == local && "$state" == active_plugin ]]; then
		store_wp plugin activate woocommerce-payments-reference > /dev/null || return 1
	fi
}

probe_field() {
	local probe_header="$1"
	local field="$2"
	printf '%s\n' "$probe_header" | tr ';' '\n' | sed -n "s/^${field}=//p"
}

request_target() {
	printf '%s\n' "$1" | sed -E 's#^[a-zA-Z]+://[^/]+##; s!#.*$!!; s#^$#/#'
}

final_probe_header() {
	tr -d '\r' < "$1" | awk '
		/^HTTP\// { probe = "" }
		/^X-WooCommerce-Native-Payments-Probe: / {
			sub( /^X-WooCommerce-Native-Payments-Probe: /, "" )
			probe = $0
		}
		END { print probe }
	'
}

request_population_page() {
	local state="$1" page="$2" path="$3" cookie="$4" body="$5"
	local result status final_url time_total final_path expected_path
	if ! result="$(PERF_COMPARE_SAMPLE_KIND=population curl --fail-with-body --location --max-redirs 3 --silent --show-error -b "$cookie" -c "$cookie" -o "$body" -w '%{http_code}\t%{url_effective}\t%{time_total}\n' "$REQUEST_BASE$path")"; then
		echo "Invalid populated session ($state): $page request failed." >&2
		return 1
	fi
	IFS=$'\t' read -r status final_url time_total <<EOF
$result
EOF
	final_path="$(request_target "$final_url")"
	expected_path="$(request_target "$path")"
	if [[ ! "$status" =~ ^2[0-9][0-9]$ ]]; then echo "Invalid populated session ($state): $page HTTP status $status." >&2; return 1; fi
	if [[ "$final_path" != "$expected_path" ]]; then echo "Invalid populated session ($state): $page final path $final_path, expected $expected_path." >&2; return 1; fi
}

prepare_populated_session() {
	local state="$1"
	local cookie="$2"
	local cart_api_body="$TEMP_ROOT/$state-population-cart-api.body"
	local cart_body="$TEMP_ROOT/$state-population-cart.body"
	local checkout_body="$TEMP_ROOT/$state-population-checkout.body"
	: > "$cookie"
	PERF_COMPARE_SAMPLE_KIND=population curl --fail-with-body --location --max-redirs 3 --silent --show-error -b "$cookie" -c "$cookie" -o /dev/null "$REQUEST_BASE/?add-to-cart=$PRODUCT_ID&quantity=1" > /dev/null || { echo "Invalid populated session ($state): product add failed." >&2; return 1; }
	request_population_page "$state" 'cart API' "$CART_API_PATH" "$cookie" "$cart_api_body" || return 1
	grep -Eq '"items"[[:space:]]*:[[:space:]]*\[[[:space:]]*\{' "$cart_api_body" || { echo "Invalid populated session ($state): Store API cart is empty." >&2; return 1; }
	request_population_page "$state" cart "$CART_PATH" "$cookie" "$cart_body" || return 1
	request_population_page "$state" checkout "$CHECKOUT_PATH" "$cookie" "$checkout_body" || return 1
}

capture_page() {
	local state="$1" page="$2" path="$3" suffix="$4" cookie="$5" sample_kind="$6"
	local trace_state="${7:-}"
	local headers="$TEMP_ROOT/$state-$page-$suffix.headers" body="$TEMP_ROOT/$state-$page-$suffix.body"
	local result status final_url final_path expected_path queries memory hooks http actual_state tier owner bootstrap probe_header time_total
	if [[ -n "$trace_state" ]]; then
		result="$(PERF_COMPARE_SAMPLE_KIND="$sample_kind" curl --fail-with-body --location --max-redirs 3 --silent --show-error -b "$cookie" -c "$cookie" -D "$headers" -o "$body" -w '%{http_code}\t%{url_effective}\t%{time_total}\n' -H "X-WooCommerce-Native-Payments-Perf-Trace: $trace_state" "$REQUEST_BASE$path")" || result=''
	else
		result="$(PERF_COMPARE_SAMPLE_KIND="$sample_kind" curl --fail-with-body --location --max-redirs 3 --silent --show-error -b "$cookie" -c "$cookie" -D "$headers" -o "$body" -w '%{http_code}\t%{url_effective}\t%{time_total}\n' "$REQUEST_BASE$path")" || result=''
	fi
	if [[ -z "$result" ]]; then
		echo "Invalid $suffix ($state/$page): HTTP request failed." >&2
		return 1
	fi
	IFS=$'\t' read -r status final_url time_total <<EOF
$result
EOF
	expected_path="$(request_target "$path")"; final_path="$(request_target "$final_url")"
	if [[ ! "$status" =~ ^2[0-9][0-9]$ ]]; then echo "Invalid $suffix ($state/$page): HTTP status $status." >&2; return 1; fi
	if [[ "$final_path" != "$expected_path" ]]; then echo "Invalid $suffix ($state/$page): final path $final_path, expected $expected_path." >&2; return 1; fi
	probe_header="$(final_probe_header "$headers")"
	if [[ ! "$probe_header" =~ ^state=[a-z_]+\;tier=[a-z]+\;owner=(native|plugin)\;bootstrap_calls=[0-9]+\;queries=[0-9]+\;used_peak_bytes=[0-9]+\;hooks=[0-9]+\;files=[0-9]+\;http=[0-9]+$ ]]; then echo "Invalid $suffix ($state/$page): missing or malformed probe header." >&2; return 1; fi
	actual_state="$(probe_field "$probe_header" state)"; tier="$(probe_field "$probe_header" tier)"; owner="$(probe_field "$probe_header" owner)"; bootstrap="$(probe_field "$probe_header" bootstrap_calls)"
	queries="$(probe_field "$probe_header" queries)"; memory="$(probe_field "$probe_header" used_peak_bytes)"; hooks="$(probe_field "$probe_header" hooks)"; http="$(probe_field "$probe_header" http)"
	case "$state" in baseline_noop) expected_tier=noop; expected_owner=native ;; active_plugin) expected_tier=active; expected_owner=plugin ;; active_native) expected_tier=active; expected_owner=native ;; *) expected_tier="$state"; expected_owner=native ;; esac
	if [[ "$actual_state" != "$state" || "$tier" != "$expected_tier" || "$owner" != "$expected_owner" || "$bootstrap" != '1' ]]; then echo "Invalid $suffix ($state/$page): observed state=$actual_state tier=$tier owner=$owner bootstrap_calls=$bootstrap." >&2; return 1; fi
	if [[ "$http" != 0 ]]; then echo "Invalid $suffix ($state/$page): observed $http outbound HTTP requests." >&2; return 1; fi
	printf '%s\t%s\t%s\t%s\n' "$queries" "$memory" "$hooks" "$time_total" > "$TEMP_ROOT/$state-$page-$suffix.metrics"
}

capture_attribution() {
	local state cookie
	for state in baseline_noop disabled; do
		cookie="$TEMP_ROOT/$state-attribution.cookies"
		reset_database || return 1
		prepare_state "$state" || return 1
		prepare_populated_session "$state-attribution" "$cookie" || return 1
		capture_page "$state" front "$FRONT_PATH" attribution "$cookie" attribution "$state" || return 1
	done
}

sample_state() {
	local state="$1" page path
	local pages=(front shop product cart checkout)
	local paths=("$FRONT_PATH" "$SHOP_PATH" "$PRODUCT_PATH" "$CART_PATH" "$CHECKOUT_PATH")
	local index=0
	local cookie="$TEMP_ROOT/$state.cookies"
	reset_database || { echo "Could not reset the database for performance state: $state" >&2; return 1; }
	prepare_state "$state" || { echo "Could not prepare performance state: $state" >&2; return 1; }
	prepare_populated_session "$state" "$cookie" || return 1
	while [[ $index -lt ${#pages[@]} ]]; do
		page="${pages[$index]}"; path="${paths[$index]}"
		capture_page "$state" "$page" "$path" warm-up "$cookie" primary-warmup || return 1
		capture_page "$state" "$page" "$path" capture "$cookie" primary-capture || return 1
		index=$((index + 1))
	done
	if [[ "$state" == active_plugin ]]; then reset_database || return 1; fi
}

write_rows() {
	local state page metrics queries memory hooks reference reference_metrics reference_queries reference_memory reference_hooks qd md hd verdict
	local states=(baseline_noop disabled available connected active_native active_plugin)
	local pages=(front shop product cart checkout)
	: > "$OUTPUT"
	printf 'state\tpage\tqueries\tused_peak_bytes\thooks\treference\tquery_delta\tused_peak_bytes_delta\thook_delta\tverdict\n' >> "$OUTPUT"
	for state in "${states[@]}"; do
		if [[ "$MODE" == 'ci' && "$state" != 'baseline_noop' && "$state" != 'disabled' && "$state" != 'active_native' ]]; then continue; fi
		for page in "${pages[@]}"; do
			metrics="$TEMP_ROOT/$state-$page-capture.metrics"
			if [[ ! -f "$metrics" ]]; then printf '%s\t%s\tNA\tNA\tNA\tinvalid\tNA\tNA\tNA\tfail\n' "$state" "$page" >> "$OUTPUT"; GATE_FAILED=1; continue; fi
			IFS=$'\t' read -r queries memory hooks _ < "$metrics"
			if [[ "$state" == 'baseline_noop' || "$state" == 'active_plugin' ]]; then
				reference="$state"; qd=0; md=0; hd=0; verdict=pass
			elif [[ "$MODE" == 'ci' && "$state" == 'active_native' ]]; then
				reference='not_evaluated'; qd=NA; md=NA; hd=NA; verdict=not_evaluated
			else
				if [[ "$state" == 'active_native' ]]; then reference='active_plugin'; else reference='baseline_noop'; fi
				reference_metrics="$TEMP_ROOT/$reference-$page-capture.metrics"
				if [[ ! -f "$reference_metrics" ]]; then printf '%s\t%s\t%s\t%s\t%s\t%s\tNA\tNA\tNA\tfail\n' "$state" "$page" "$queries" "$memory" "$hooks" "$reference" >> "$OUTPUT"; GATE_FAILED=1; continue; fi
				IFS=$'\t' read -r reference_queries reference_memory reference_hooks _ < "$reference_metrics"
				qd=$((queries - reference_queries)); md=$((memory - reference_memory)); hd=$((hooks - reference_hooks))
				if [[ "$state" == 'active_native' ]]; then
					if [[ $qd -le 2 && $md -le 2097152 ]]; then verdict=pass; else verdict=fail; fi
				else
					if [[ $qd -le 1 && $md -le 524288 && $hd -le 5 ]]; then verdict=pass; else verdict=fail; fi
				fi
			fi
			if [[ "$verdict" == 'fail' ]]; then GATE_FAILED=1; fi
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$state" "$page" "$queries" "$memory" "$hooks" "$reference" "$qd" "$md" "$hd" "$verdict" >> "$OUTPUT"
		done
	done
}

timing_gate() {
	local pair state first second cookie metrics elapsed result median_native median_plugin percentage verdict
	local native_times="$TEMP_ROOT/native-times" plugin_times="$TEMP_ROOT/plugin-times"
	local invalid=0
	: > "$native_times"; : > "$plugin_times"
	for pair in 1 2 3 4 5 6 7 8 9; do
		if ((pair % 2)); then first=active_native; second=active_plugin; else first=active_plugin; second=active_native; fi
		for state in "$first" "$second"; do
			cookie="$TEMP_ROOT/$state-timing-$pair.cookies"
			if ! reset_database || ! prepare_state "$state" || ! prepare_populated_session "$state-timing-$pair" "$cookie" || ! capture_page "$state" checkout "$CHECKOUT_PATH" "timing-$pair" "$cookie" timing; then
				invalid=1
			else
				metrics="$TEMP_ROOT/$state-checkout-timing-$pair.metrics"
				IFS=$'\t' read -r _ _ _ elapsed < "$metrics"
				if [[ "$state" == 'active_native' ]]; then printf '%s\n' "$elapsed" >> "$native_times"; else printf '%s\n' "$elapsed" >> "$plugin_times"; fi
			fi
			if [[ "$state" == active_plugin ]] && ! reset_database; then invalid=1; fi
		done
	done
	if [[ $invalid -ne 0 || "$(wc -l < "$native_times" | tr -d ' ')" != '9' || "$(wc -l < "$plugin_times" | tr -d ' ')" != '9' ]]; then
		printf 'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\tNA,NA,NA,fail\n' >> "$OUTPUT"
		return 1
	fi
	if ! result="$(python3 - "$native_times" "$plugin_times" <<'PY'
from decimal import Decimal, ROUND_HALF_UP
import sys
def median(path):
    values = sorted(Decimal(line.strip()) * Decimal(1000) for line in open(path) if line.strip())
    return values[len(values) // 2]
native = median(sys.argv[1]); plugin = median(sys.argv[2]); delta = (native - plugin) * Decimal(100) / plugin
rounded = delta.quantize(Decimal("0.001"), rounding=ROUND_HALF_UP)
verdict = "pass" if delta <= Decimal("5.000") else "fail"
print(f'{native:.3f}\t{plugin:.3f}\t{rounded:.3f}\t{verdict}')
PY
	)"; then printf 'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\tNA,NA,NA,fail\n' >> "$OUTPUT"; return 1; fi
	IFS=$'\t' read -r median_native median_plugin percentage verdict <<EOF
$result
EOF
	printf 'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\t%s,%s,%s,%s\n' "$median_native" "$median_plugin" "$percentage" "$verdict" >> "$OUTPUT"
	[[ "$verdict" == pass ]]
}

main() {
	local states=(baseline_noop disabled available connected active_native active_plugin)
	local state
	parse_arguments "$@"
	TEMP_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-perf-compare.XXXXXX")"
	DATABASE_SNAPSHOT="/var/www/html/.woocommerce-native-perf-${PPID}-${RANDOM}.sql"
	trap on_exit EXIT
	curl --fail-with-body --location --max-redirs 3 --silent --show-error -D /dev/null -o /dev/null "$STORE_URL/" > /dev/null || { echo "The store did not answer: $STORE_URL" >&2; return 1; }
	discover_request_base || { echo 'The tests service did not provide a valid canonical store origin.' >&2; return 1; }
	if canonical_is_active; then echo 'The canonical WooPayments plugin must be inactive.' >&2; return 1; fi
	seed_and_export_database || { echo 'Could not seed and export the disposable database.' >&2; return 1; }
	install_probe_and_reference
	for state in "${states[@]}"; do
		if [[ "$MODE" == ci && "$state" != baseline_noop && "$state" != disabled && "$state" != active_native ]]; then continue; fi
		if ! sample_state "$state"; then echo "Invalid sample state: $state" >&2; SAMPLE_FAILED=1; break; fi
	done
	if [[ "$MODE" == local && $SAMPLE_FAILED -eq 0 ]] && ! capture_attribution; then
		echo 'Could not capture baseline_noop and disabled attribution artifacts.' >&2
		SAMPLE_FAILED=1
	fi
	write_rows
	if [[ "$MODE" == local ]]; then
		if [[ $SAMPLE_FAILED -ne 0 ]]; then
			printf 'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\tNA,NA,NA,fail\n' >> "$OUTPUT"
			GATE_FAILED=1
		elif ! timing_gate; then
			GATE_FAILED=1
		fi
	fi
	if [[ $SAMPLE_FAILED -ne 0 || $GATE_FAILED -ne 0 ]]; then return 1; fi
}

main "$@"
