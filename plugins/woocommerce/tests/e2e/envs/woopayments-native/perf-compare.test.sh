#!/usr/bin/env bash

set -euo pipefail

fake_pnpm() {
	local root="${PERF_FAKE_ROOT:?}" service command snapshot state value
	printf 'COMMAND\t%s\n' "$*" >> "$root/commands.log"
	[[ "$1" == 'exec' && "$2" == 'wp-env' ]] || exit 64
	shift 2
	if [[ "${1:-}" == '--config' ]]; then
		[[ "$2" == "$root/wp-env.json" ]] || exit 64
		shift 2
	fi
	[[ "$1" == 'run' ]]
	service="$2"
	shift 2
	[[ "$service" == "${PERF_EXPECTED_SERVICE:-tests-cli}" ]] || exit 64
	command="$1"
	shift

	if [[ "$command" != 'wp' ]]; then
		case "$command" in
		cp)
			[[ "$2" == 'wp-content/mu-plugins/woopayments-native-perf-probe.php' ]]
			mkdir -p "$root/site/wp-content/mu-plugins"
			printf 'probe\n' > "$root/site/$2"
			printf 'PROBE_INSTALL\n' >> "$root/events.log"
			;;
		rm)
			for value in "$@"; do
				case "$value" in
				wp-content/mu-plugins/woopayments-native-perf-probe.php)
					rm -f "$root/site/$value"
					printf 'PROBE_REMOVE\n' >> "$root/events.log"
					;;
				wp-content/plugins/woocommerce-payments-reference|wp-content/plugins/woocommerce-payments-reference-stage)
					if [[ "${PERF_FAKE_CASE:-}" == 'reference-remove-failure' ]]; then exit 65; fi
					rm -rf "$root/site/$value"
					printf 'REFERENCE_REMOVE\t%s\n' "$value" >> "$root/events.log"
					;;
				/var/www/html/.woocommerce-native-perf-*.sql)
					rm -f "$root/site/db-snapshot.sql"
					printf 'SNAPSHOT_REMOVE\t%s\n' "$value" >> "$root/events.log"
					;;
				-rf|-f) ;;
				*) exit 65 ;;
				esac
			done
			;;
		*) exit 65 ;;
		esac
		return
	fi

	if [[ "${1:-}" == '--user=1' ]]; then shift; fi
	case "${1:-}" in
	eval)
		printf 'SEED\n' >> "$root/events.log"
		;;
	eval-file)
		[[ "$2" == 'wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native/perf-probe.php' && "$3" == '--use-include' && "$4" == 'woocommerce-native-perf-helper' ]]
		case "$5" in
		ensure-product)
			printf 'PRODUCT_READY\n' >> "$root/events.log"
			printf '17\t/\t/?post_type=product\t/?product=perf-product\t/?page_id=6\t/?page_id=7\t/index.php?rest_route=/wc/store/v1/cart\n'
			;;
		install-reference)
			printf 'REFERENCE_INSTALL\t%s\t%s\n' "$6" "$7" >> "$root/events.log"
			[[ "$6" == 'https://github.com/Automattic/woocommerce-payments/releases/download/10.8.0/woocommerce-payments.zip' ]]
			[[ "$7" == '9b80d9002e831f62d72bba09dec8b09ca3dc8116c637bae94c74160063767cc9' ]]
			[[ "$8" == 'woocommerce-payments-reference' ]]
			if [[ "${PERF_FAKE_CASE:-}" == 'reference-integrity' ]]; then exit 66; fi
			mkdir -p "$root/site/wp-content/plugins/woocommerce-payments-reference"
			printf 'reference\n' > "$root/site/wp-content/plugins/woocommerce-payments-reference/woocommerce-payments.php"
			;;
		*) exit 66 ;;
		esac
		;;
	db)
		case "$2" in
		export)
			snapshot="$3"
			printf 'DB_EXPORT\t%s\n' "$snapshot" >> "$root/events.log"
			if [[ "${PERF_FAKE_CASE:-}" == 'export-failure' ]]; then exit 67; fi
			printf '%s\n' "$snapshot" > "$root/snapshot-path"
			printf 'database\n' > "$root/site/db-snapshot.sql"
			;;
		import)
			snapshot="$3"
			printf 'DB_IMPORT\t%s\n' "$snapshot" >> "$root/events.log"
			[[ -f "$root/site/db-snapshot.sql" && "$(< "$root/snapshot-path")" == "$snapshot" ]]
			if [[ "${PERF_FAKE_CASE:-}" == 'import-failure' ]]; then exit 68; fi
			printf 'false\n' > "$root/reference-active"
			;;
		*) exit 67 ;;
		esac
		;;
	option)
		case "$2" in
		get)
			[[ "$3" == home ]]
			printf 'http://canonical.native.test:8187\n'
			;;
		update)
			if [[ "$3" == 'woocommerce_coming_soon' && "$4" == no ]]; then
				: > "$root/store-open"
				printf 'STORE_OPEN\n' >> "$root/events.log"
			fi
			if [[ "$3" == '_wcpay_feature_customer_multi_currency' ]]; then
				printf 'MULTI_CURRENCY_FEATURE\t%s\n' "$4" >> "$root/events.log"
			fi
			if [[ "$3" == 'woocommerce_native_payments_perf_probe_control' ]]; then
				state="$(printf '%s' "$4" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
				printf '%s\n' "$state" > "$root/current-state"
				printf 'STATE\t%s\n' "$state" >> "$root/events.log"
			fi
			if [[ "$3" == 'wcpay_account_data' ]]; then
				printf 'ACCOUNT_DATA\t%s\n' "$4" >> "$root/events.log"
			fi
			;;
		*) exit 68 ;;
		esac
		;;
	plugin)
		case "$2:$3" in
		is-active:woocommerce-payments) [[ "$(< "$root/canonical-active")" == 'true' ]] ;;
		get:woocommerce-payments-reference)
			[[ -f "$root/site/wp-content/plugins/woocommerce-payments-reference/woocommerce-payments.php" ]]
			if [[ "${PERF_FAKE_CASE:-}" == 'reference-version' ]]; then printf '10.7.0\n'; else printf '10.8.0\n'; fi
			;;
		activate:woocommerce-payments-reference)
			[[ -f "$root/site/wp-content/plugins/woocommerce-payments-reference/woocommerce-payments.php" ]]
			printf 'true\n' > "$root/reference-active"
			printf 'REFERENCE_ACTIVATE\n' >> "$root/events.log"
			;;
		*) exit 69 ;;
		esac
		;;
	*) exit 70 ;;
	esac
}

fake_curl() {
	local root="${PERF_FAKE_ROOT:?}" headers='' body='' cookie='' trace_header='' url='' state kind status=200 final_url queries memory hooks files=250 owner tier time_total=.100000 bootstrap=1 http=0 target
	while (($#)); do
		case "$1" in
		-D) headers="$2"; shift 2 ;;
		-o) body="$2"; shift 2 ;;
		-b|-c) cookie="$2"; shift 2 ;;
		-H) trace_header="$2"; shift 2 ;;
		-w|--max-redirs) shift 2 ;;
		--fail-with-body|--location|--silent|--show-error) shift ;;
		*) url="$1"; shift ;;
		esac
	done
	state="$(< "$root/current-state")"
	kind="${PERF_COMPARE_SAMPLE_KIND:-preflight}"
	if [[ "$kind" != preflight ]]; then
		target="${url#http://canonical.native.test:8187}"
		case "$target" in
		/|"/?add-to-cart=17&quantity=1"|"/?post_type=product"|"/?product=perf-product"|"/?page_id=6"|"/?page_id=7"|"/index.php?rest_route=/wc/store/v1/cart") ;;
		*) exit 72 ;;
		esac
	fi
	printf 'CURL\t%s\t%s\t%s\t%s\t%s\n' "$kind" "$state" "$url" "$cookie" "$trace_header" >> "$root/samples.log"
	final_url="$url"
	if [[ "$url" == *'add-to-cart=17'* ]]; then : > "$cookie.populated"; fi
	if [[ "$url" == *'rest_route=/wc/store/v1/cart'* ]]; then printf '{"items":[{"id":17}]}\n' > "$body"; fi
	if [[ -n "$body" && ! -s "$body" ]]; then printf '<main>page</main>\n' > "$body"; fi

	if [[ -n "$headers" && "$kind" != preflight ]]; then
		case "$state" in
		baseline_noop) tier=noop; owner=native; queries=100; memory=1000000; hooks=100 ;;
		disabled|available|connected) tier="$state"; owner=native; queries=101; memory=1524288; hooks=105 ;;
		active_native) tier=active; owner=native; queries=202; memory=5097152; hooks=163 ;;
		active_plugin) tier=active; owner=plugin; queries=200; memory=3000000; hooks=160 ;;
		*) exit 71 ;;
		esac
		[[ "$state" == 'active_native' ]] && time_total=.105000
		case "${PERF_FAKE_CASE:-}" in
		query-fail) [[ "$state" != connected ]] || queries=102 ;;
		memory-fail) [[ "$state" != available ]] || memory=1524289 ;;
		hook-fail) [[ "$state" != disabled ]] || hooks=106 ;;
		timing-fail) [[ "$kind" != timing || "$state" != active_native ]] || time_total=.105001 ;;
		missing-header) headers='' ;;
		malformed-header) tier='???' ;;
		invalid-files) files=unknown ;;
		wrong-owner) owner=plugin ;;
		wrong-bootstrap) bootstrap=2 ;;
		outbound-http) http=1 ;;
		wrong-final-page) final_url="${url%/}/wrong/" ;;
		http-failure) status=503 ;;
		esac
		if [[ -n "$headers" ]]; then
			if [[ "${PERF_FAKE_CASE:-}" == 'attribution-artifact-failure' && "$kind" == attribution ]]; then
				printf 'HTTP/1.1 %s OK\r\nX-WooCommerce-Native-Payments-Probe: error=attribution-artifacts\r\n\r\n' "$status" > "$headers"
			else
				printf 'HTTP/1.1 %s OK\r\nX-WooCommerce-Native-Payments-Probe: state=%s;tier=%s;owner=%s;bootstrap_calls=%s;queries=%s;used_peak_bytes=%s;hooks=%s;files=%s;http=%s\r\n\r\n' "$status" "$state" "$tier" "$owner" "$bootstrap" "$queries" "$memory" "$hooks" "$files" "$http" > "$headers"
			fi
		fi
		printf 'SAMPLE\t%s\t%s\n' "$kind" "$state" >> "$root/events.log"
	fi
	printf '%s\t%s\t%s\n' "$status" "$final_url" "$time_total"
}

fake_date() {
	[[ "$*" == '+%s' ]] || exit 73
	printf 'SEED_TIME\t1788898000\n' >> "${PERF_FAKE_ROOT:?}/events.log"
	printf '1788898000\n'
}

if [[ "${BASH_SOURCE[0]}" != "$0" ]]; then return; fi
if [[ "$(basename "$0")" == 'pnpm' ]]; then fake_pnpm "$@"; exit; fi
if [[ "$(basename "$0")" == 'curl' ]]; then fake_curl "$@"; exit; fi
if [[ "$(basename "$0")" == 'date' ]]; then fake_date "$@"; exit; fi

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly RUNNER="$SCRIPT_DIR/perf-compare.sh"
readonly PROBE="$SCRIPT_DIR/perf-probe.php"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/perf-compare-test.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

fail() { echo "$1" >&2; exit 1; }

php -r '
define( "ABSPATH", __DIR__ );
$registered = array();
function get_option() {
	return array( "state" => "baseline_noop", "reference_plugin_slug" => "woocommerce-payments-reference/woocommerce-payments.php" );
}
function add_filter( $hook ) {
	global $registered;
	$registered[] = "filter:$hook";
}
function add_action( $hook ) {
	global $registered;
	$registered[] = "action:$hook";
}
require $argv[1];
$expected = array(
	"filter:woocommerce_native_payments_bootstrap_enabled",
	"filter:woocommerce_native_payments_enabled",
	"filter:pre_http_request",
	"action:plugins_loaded",
	"action:shutdown",
);
exit( $registered === $expected ? 0 : 1 );
' "$PROBE" || fail 'The MU probe did not register its measurement hooks.'

probe_attribution_dir="$TEST_ROOT/probe-attribution"
mkdir -p "$probe_attribution_dir"
php -r '
define( "ABSPATH", __DIR__ );
define( "WP_PLUGIN_DIR", "/plugins" );
$registered_filters = array();
$registered_actions = array();
$upload_dir = $argv[2];
$_SERVER["HTTP_X_WOOCOMMERCE_NATIVE_PAYMENTS_PERF_TRACE"] = "baseline_noop";
function get_option() {
	return array( "state" => "baseline_noop", "reference_plugin_slug" => "woocommerce-payments-reference/woocommerce-payments.php" );
}
function add_filter( $hook, $callback ) {
	global $registered_filters;
	$registered_filters[ $hook ] = $callback;
}
function add_action( $hook, $callback ) {
	global $registered_actions;
	$registered_actions[ $hook ] = $callback;
}
function wp_upload_dir() {
	global $upload_dir;
	return array( "basedir" => $upload_dir );
}
function wp_debug_backtrace_summary() {
	return array( "wpdb::query", "WooCommerce_Feature::load" );
}
function get_num_queries() {
	return 5;
}
$wp_filter = array( "init" => true );
require $argv[1];
if ( ! isset( $registered_filters["query"] ) ) {
	fwrite( STDERR, "The attribution request did not register the query recorder.\n" );
	exit( 1 );
}
$untrusted_query = false;
if ( $untrusted_query !== call_user_func( $registered_filters["query"], $untrusted_query ) ) {
	fwrite( STDERR, "The query recorder did not preserve an untrusted query filter value.\n" );
	exit( 1 );
}
call_user_func( $registered_filters["query"], " SELECT  option_value FROM wp_options WHERE option_name = '\''merchant-action-2026'\'' AND option_id = 123 " );
call_user_func( $registered_actions["shutdown"] );
$files_path = $upload_dir . "/woocommerce-native-perf-baseline_noop-files.txt";
$queries_path = $upload_dir . "/woocommerce-native-perf-baseline_noop-queries.tsv";
if ( ! is_file( $files_path ) || ! in_array( realpath( $argv[1] ), file( $files_path, FILE_IGNORE_NEW_LINES ), true ) ) {
	fwrite( STDERR, "The attribution request did not write its included-file list.\n" );
	exit( 1 );
}
$expected_query = "SELECT option_value FROM wp_options WHERE option_name = '\''merchant-action-2026'\'' AND option_id = ?\tWooCommerce_Feature::load\n";
if ( ! is_file( $queries_path ) || $expected_query !== file_get_contents( $queries_path ) ) {
	fwrite( STDERR, "The attribution request did not write its normalized query trace.\n" );
	exit( 1 );
}
' "$PROBE" "$probe_attribution_dir" || fail 'The MU probe did not write isolated attribution artifacts.'

php -r '
define( "ABSPATH", __DIR__ );
define( "WP_PLUGIN_DIR", "/plugins" );
$registered_actions = array();
$_SERVER["HTTP_X_WOOCOMMERCE_NATIVE_PAYMENTS_PERF_TRACE"] = "baseline_noop";
function get_option() {
	return array( "state" => "baseline_noop", "reference_plugin_slug" => "woocommerce-payments-reference/woocommerce-payments.php" );
}
function add_filter() {}
function add_action( $hook, $callback ) {
	global $registered_actions;
	$registered_actions[ $hook ] = $callback;
}
function wp_upload_dir() {
	return array( "basedir" => __DIR__ . "/unavailable-attribution-artifacts" );
}
require $argv[1];
$method = new ReflectionMethod( "WooCommerce_Native_Payments_Perf_Probe", "write_attribution_artifacts" );
global $woocommerce_native_payments_perf_probe;
exit( false === $method->invoke( $woocommerce_native_payments_perf_probe ) ? 0 : 1 );
' "$PROBE" || fail 'The MU probe did not report unavailable attribution artifact storage.'

assert_trace_is_rejected() {
	local trace_state="$1" control_state="$2"
	php -r '
define( "ABSPATH", __DIR__ );
$registered_filters = array();
$control_state = $argv[2];
$_SERVER["HTTP_X_WOOCOMMERCE_NATIVE_PAYMENTS_PERF_TRACE"] = $argv[3];
function get_option() {
	global $control_state;
	return array( "state" => $control_state, "reference_plugin_slug" => "woocommerce-payments-reference/woocommerce-payments.php" );
}
function add_filter( $hook ) {
	global $registered_filters;
	$registered_filters[] = $hook;
}
function add_action() {}
require $argv[1];
exit( in_array( "query", $registered_filters, true ) ? 1 : 0 );
' "$PROBE" "$control_state" "$trace_state" || fail "The probe accepted an unauthorized $trace_state attribution trace for $control_state."
}

assert_trace_is_rejected disabled baseline_noop
assert_trace_is_rejected active_native active_native

setup_case() {
	local name="$1" root="$TEST_ROOT/$1"
	mkdir -p "$root/bin" "$root/output" "$root/site/wp-content/plugins" "$root/site/wp-content/mu-plugins"
	: > "$root/commands.log"; : > "$root/events.log"; : > "$root/samples.log"; : > "$root/wp-env.json"
	printf 'false\n' > "$root/canonical-active"
	printf 'false\n' > "$root/reference-active"
	printf 'baseline_noop\n' > "$root/current-state"
	ln -s "$SCRIPT_DIR/perf-compare.test.sh" "$root/bin/pnpm"
	ln -s "$SCRIPT_DIR/perf-compare.test.sh" "$root/bin/curl"
	ln -s "$SCRIPT_DIR/perf-compare.test.sh" "$root/bin/date"
}

run_case() {
	local name="$1" scenario="$2" expected="$3" mode="$4" root="$TEST_ROOT/$1" status=0 service
	setup_case "$name"
	service="$([[ "$mode" == local ]] && echo tests-cli || echo cli)"
	PATH="$root/bin:$PATH" PERF_FAKE_ROOT="$root" PERF_FAKE_CASE="$scenario" PERF_EXPECTED_SERVICE="$service" \
		"$RUNNER" --mode "$mode" --store-url 'http://native.test:8187' --wp-env-config "$root/wp-env.json" --wp-env-service "$service" --output "$root/output/result.tsv" > "$root/stdout" 2> "$root/stderr" || status=$?
	[[ "$status" == "$expected" ]] || fail "$name expected exit $expected, got $status: $(< "$root/stderr")"
}

assert_cleaned() {
	local root="$1"
	[[ ! -e "$root/site/wp-content/mu-plugins/woopayments-native-perf-probe.php" ]] || fail "probe remained in $root"
	[[ ! -e "$root/site/wp-content/plugins/woocommerce-payments-reference" ]] || fail "reference remained in $root"
	[[ ! -e "$root/site/db-snapshot.sql" ]] || fail "database snapshot remained in $root"
	[[ "$(< "$root/canonical-active")" == false ]] || fail "canonical plugin changed in $root"
}

run_case local-pass pass 0 local
local_root="$TEST_ROOT/local-pass"; local_output="$local_root/output/result.tsv"
expected_account='{"data":{"account_id":"acct_native_ci","country":"US","default_currency":"usd","payments_enabled":true,"payouts_enabled":true,"details_submitted":true,"is_live":false,"test_publishable_key":"pk_test_native_ci","live_publishable_key":"","statement_descriptor":"NATIVE CI","statement_descriptor_kanji":"","statement_descriptor_kana":"","business_profile":{"name":"Native CI store","url":"https://example.test","support_address":{"country":"US"},"support_email":"support@example.test","support_phone":"+10000000000"},"branding":{"logo":"","icon":"","primary_color":"#000000","secondary_color":"#ffffff"},"communications_email":"owner@example.test","store_currencies":{"default":"usd"},"customer_currencies":{"supported":["usd","eur","aud","cad","chf","gbp","jpy","nzd","sek"]},"account_details":{"account_status":{"text":"Enabled"},"payout_status":{"text":"Enabled"},"banner":null},"deposits":{"interval":"daily","weekly_anchor":"monday","monthly_anchor":1,"delay_days":2,"status":"enabled","restrictions":"","completed_waiting_period":true},"platform_checkout_eligible":true,"capabilities":{"card_payments":"active","klarna_payments":"active"},"supported_payment_methods":["card","klarna"],"fees":{"card":[],"klarna":[]}},"fetched":1788898000,"errored":false,"consecutive_errors":0}'
[[ "$(grep -Fc $'ACCOUNT_DATA\t'"$expected_account" "$local_root/events.log")" == 21 ]] || fail 'Connected and active states did not use the complete seed-time account cache.'
[[ "$(grep -c $'^SEED_TIME\t1788898000$' "$local_root/events.log")" == 1 ]] || fail 'The account cache timestamp was not captured exactly once at seed time.'
[[ "$(wc -l < "$local_output" | tr -d ' ')" == 32 ]] || fail 'Local output is not the complete 30-row matrix plus timing row.'
[[ "$(grep -c $'^CURL\tprimary-warmup\t' "$local_root/samples.log")" == 30 ]] || fail 'Local matrix did not warm all six states across five routes.'
[[ "$(grep -c $'^CURL\tprimary-capture\t' "$local_root/samples.log")" == 30 ]] || fail 'Local matrix did not capture all six states across five routes.'
if awk -F '\t' '$2 != "preflight" && $4 !~ /^http:\/\/canonical.native.test:8187\// { bad=1 } END { exit bad ? 0 : 1 }' "$local_root/samples.log"; then fail 'Shopper requests did not share the service home origin and cookie scope.'; fi
for target in '/' '/?post_type=product' '/?product=perf-product' '/?page_id=6' '/?page_id=7'; do
	grep -Fq $'CURL\tprimary-capture\tbaseline_noop\thttp://canonical.native.test:8187'"$target"$'\t' "$local_root/samples.log" || fail "The canonical measured route was not used: $target"
done
[[ "$(grep -c $'^CURL\ttiming\t' "$local_root/samples.log")" == 18 ]] || fail 'Local timing did not run nine alternating pairs.'
[[ "$(awk -F '\t' '$2 == "population" && $4 ~ /wc\/store\/v1\/cart/ { count++ } END { print count + 0 }' "$local_root/samples.log")" == 26 ]] || fail 'Every state/timing/attribution sample did not prove a populated Store API cart.'
[[ "$(grep -c $'^CURL\tattribution\tbaseline_noop\t.*\tX-WooCommerce-Native-Payments-Perf-Trace: baseline_noop$' "$local_root/samples.log")" == 1 ]] || fail 'Local attribution did not trace baseline_noop exactly once.'
[[ "$(grep -c $'^CURL\tattribution\tdisabled\t.*\tX-WooCommerce-Native-Payments-Perf-Trace: disabled$' "$local_root/samples.log")" == 1 ]] || fail 'Local attribution did not trace disabled exactly once.'
[[ "$(grep -c $'^DB_EXPORT\t' "$local_root/events.log")" == 1 ]] || fail 'The disposable database was not exported exactly once.'
[[ "$(grep -c $'^DB_IMPORT\t' "$local_root/events.log")" == 37 ]] || fail 'The disposable database reset count is wrong.'
[[ "$(grep -c $'^MULTI_CURRENCY_FEATURE\t"0"$' "$local_root/events.log")" == 26 ]] || fail 'Every local state preparation must disable Multi-Currency before native-tier measurement.'
[[ "$(grep -c $'^REFERENCE_ACTIVATE$' "$local_root/events.log")" == 10 ]] || fail 'The isolated reference was not activated for its ten samples.'
awk '/^DB_EXPORT/{seen=1} /^DB_IMPORT/ && !seen{exit 1}' "$local_root/events.log" || fail 'Database import preceded the one export.'
[[ "$(awk '$1 == "SEED" || $1 == "STORE_OPEN" || $1 == "PRODUCT_READY" || $1 == "DB_EXPORT" { printf "%s ", $1 }' "$local_root/events.log" | head -c 40)" == 'SEED STORE_OPEN PRODUCT_READY DB_EXPORT ' ]] || fail 'The open store and product were not ready after seed and before the one database export.'
awk '/^SEED$/{seed=NR} /^SEED_TIME\t/{seed_time=NR} /^DB_EXPORT\t/{exported=NR} END{exit seed < seed_time && seed_time < exported ? 0 : 1}' "$local_root/events.log" || fail 'The account timestamp was not captured after seed and before export.'
awk '/^STATE/{if(last !~ /^DB_IMPORT/) exit 1} {last=$0}' "$local_root/events.log" || fail 'A state was prepared without an immediately preceding database reset.'
awk '/^SAMPLE\tprimary-capture\tactive_plugin$/{if(++primary == 5) pending=1; next} /^SAMPLE\ttiming\tactive_plugin$/{pending=1; next} pending && /^DB_IMPORT/{pending=0; next} pending && /^(STATE|SAMPLE)/{exit 1} END{exit pending}' "$local_root/events.log" || fail 'A reference sample was not followed by a database reset.'
grep -Fq $'disabled\tfront\t101\t1524288\t105\tbaseline_noop\t1\t524288\t5\tpass' "$local_output" || fail 'The exact no-op ceiling did not pass.'
grep -Fq $'active_native\tfront\t202\t5097152\t163\tactive_plugin\t2\t2097152\t3\tpass' "$local_output" || fail 'The exact native/plugin ceiling did not pass.'
grep -Fq $'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\t105.000,100.000,5.000,pass' "$local_output" || fail 'The exact timing ceiling did not pass.'
assert_cleaned "$local_root"

run_case attribution-artifact-failure attribution-artifact-failure 1 local
grep -Fq 'could not write required attribution artifacts' "$TEST_ROOT/attribution-artifact-failure/stderr" || fail 'Local attribution accepted a probe artifact-write failure.'
assert_cleaned "$TEST_ROOT/attribution-artifact-failure"

for failure in query-fail memory-fail hook-fail timing-fail; do
	run_case "$failure" "$failure" 1 local
	[[ "$(wc -l < "$TEST_ROOT/$failure/output/result.tsv" | tr -d ' ')" == 32 ]] || fail "$failure did not emit a complete deterministic TSV."
	assert_cleaned "$TEST_ROOT/$failure"
done
grep -Fq $'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\t105.001,100.000,5.001,fail' "$TEST_ROOT/timing-fail/output/result.tsv" || fail 'The 5.001% timing boundary did not fail.'

for invalid in missing-header malformed-header invalid-files wrong-owner wrong-bootstrap outbound-http wrong-final-page http-failure; do
	run_case "$invalid" "$invalid" 1 ci
	grep -Eq 'Invalid (warm-up|capture|populated session)' "$TEST_ROOT/$invalid/stderr" || fail "$invalid lacked a precise invalid-sample error."
	assert_cleaned "$TEST_ROOT/$invalid"
done
grep -Fq 'observed 1 outbound HTTP requests' "$TEST_ROOT/outbound-http/stderr" || fail 'A nonzero measured HTTP count lacked a precise invalid-sample error.'

run_case ci-pass pass 0 ci
ci_root="$TEST_ROOT/ci-pass"; ci_output="$ci_root/output/result.tsv"
[[ "$(wc -l < "$ci_output" | tr -d ' ')" == 16 ]] || fail 'CI output is not the complete three-state subset.'
[[ "$(awk -F '\t' 'NR > 1 { states[$1]=1 } END { for (state in states) print state }' "$ci_output" | sort | tr '\n' ' ')" == 'active_native baseline_noop disabled ' ]] || fail 'CI sampled the wrong states.'
[[ "$(grep -c $'^DB_EXPORT\t' "$ci_root/events.log")" == 1 && "$(grep -c $'^DB_IMPORT\t' "$ci_root/events.log")" == 4 ]] || fail 'CI did not use one export and four resets.'
[[ "$(grep -c $'^MULTI_CURRENCY_FEATURE\t"0"$' "$ci_root/events.log")" == 3 ]] || fail 'Every CI state preparation must disable Multi-Currency before native-tier measurement.'
if grep -Eq '^REFERENCE_(INSTALL|ACTIVATE)' "$ci_root/events.log"; then fail 'CI touched the reference plugin.'; fi
assert_cleaned "$ci_root"

for failure in export-failure import-failure reference-integrity reference-version reference-remove-failure; do
	run_case "$failure" "$failure" 1 local
done
[[ "$(grep -c $'^DB_EXPORT\t' "$TEST_ROOT/export-failure/events.log")" == 1 && "$(grep -c $'^SAMPLE\t' "$TEST_ROOT/export-failure/events.log")" == 0 ]] || fail 'Export failure reached sampling.'
grep -Fq $'DB_IMPORT\t' "$TEST_ROOT/import-failure/events.log" || fail 'Import failure was not propagated from a reset attempt.'
grep -Fq '9b80d9002e831f62d72bba09dec8b09ca3dc8116c637bae94c74160063767cc9' "$TEST_ROOT/reference-integrity/events.log" || fail 'Reference integrity did not use the pinned digest.'
grep -Fq 'Cleanup failed' "$TEST_ROOT/reference-remove-failure/stderr" || fail 'Reference cleanup failure did not propagate.'

for arguments in '--mode' '--store-url' '--output' '--wp-env-config' '--wp-env-service' '--mode unsupported --output result.tsv'; do
	name="argument-$(printf '%s' "$arguments" | tr ' /' '__')"; setup_case "$name"; root="$TEST_ROOT/$name"; status=0
	# shellcheck disable=SC2086 -- Deliberately split malformed argument vectors.
	PATH="$root/bin:$PATH" PERF_FAKE_ROOT="$root" "$RUNNER" $arguments > "$root/stdout" 2> "$root/stderr" || status=$?
	[[ "$status" == 2 && ! -s "$root/commands.log" ]] || fail "Argument contract failed without isolation for: $arguments"
done

echo 'perf-compare.sh tests passed.'
