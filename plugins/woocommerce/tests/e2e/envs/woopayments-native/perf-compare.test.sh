#!/usr/bin/env bash
# The single-quoted php -r programs below interpolate their variables in PHP.
# shellcheck disable=SC2016

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
		printenv) [[ "$1" == HOME ]] && echo /home/test ;;
		cmp)
			[[ "$1" == -s && "$3" == "$2.restored.sql" ]]
			cmp -s "$root/site/${2##*/}" "$root/site/${3##*/}"
			;;
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
				/home/test/.woocommerce-native-perf-*.sql)
					rm -f "$root/site/${value##*/}"
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
			printf '17\t/\t/?post_type=product\t/?product=canvas-tote\t/?page_id=6\t/?page_id=7\t/index.php?rest_route=/wc/store/v1/cart\n'
			;;
		install-reference)
			printf 'REFERENCE_INSTALL\t%s\t%s\n' "$6" "$7" >> "$root/events.log"
			[[ "$6" == 'https://github.com/Automattic/woocommerce-payments/releases/download/11.2.0/woocommerce-payments.zip' ]]
			[[ "$7" == '58ea000906c2fc6f40e9a79d5ea60ec3104ae6d5ddf4ae533c2162b3b5fe5f96' ]]
			[[ "$8" == 'woocommerce-payments-reference' ]]
			# The download path passes exactly these arguments; a --reference-zip run adds the mount path of its copy,
			# which must hold the given zip's bytes while the helper runs.
			if [[ $# -eq 8 ]]; then
				printf 'REFERENCE_DOWNLOAD\t%s\n' "$6" >> "$root/events.log"
			else
				[[ $# -eq 9 && "$9" == wp-content/plugins/woocommerce/tests/bin/tmp/woopayments-perf-reference.*/woocommerce-payments.zip ]] || exit 66
				cmp -s "${PERF_FAKE_PLUGIN_ROOT:?}/${9#wp-content/plugins/woocommerce/}" "${PERF_FAKE_REFERENCE_ZIP:?}" || exit 66
				printf 'REFERENCE_LOCAL\t%s\n' "$9" >> "$root/events.log"
			fi
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
			cp "$root/theme" "$root/site/${snapshot##*/}"
			if [[ "$snapshot" == *.restored.sql && "${PERF_FAKE_CASE:-}" == restore-mismatch ]]; then
				echo 'drift' >> "$root/site/${snapshot##*/}"
			fi
			;;
		import)
			snapshot="$3"
			printf 'DB_IMPORT\t%s\n' "$snapshot" >> "$root/events.log"
			cp "$root/site/${snapshot##*/}" "$root/theme"
			if [[ "${PERF_FAKE_CASE:-}" == 'import-failure' ]]; then exit 68; fi
			printf 'false\n' > "$root/reference-active"
			;;
		*) exit 67 ;;
		esac
		;;
	theme)
		[[ "$2" == activate && "$3" == storefront ]]
		echo storefront > "$root/theme"
		echo THEME_ACTIVE >> "$root/events.log"
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
			if [[ "$3" == 'woocommerce_feature_multi_currency_enabled' ]]; then
				printf 'CORE_MULTI_CURRENCY_FEATURE\t%s\n' "$4" >> "$root/events.log"
			fi
			if [[ "$3" == 'woocommerce_feature_woopayments_enabled' ]]; then
				printf 'WOOPAYMENTS_FEATURE\t%s\t%s\n' "$(< "$root/current-state")" "$4" >> "$root/events.log"
			fi
			if [[ "$3" == 'e2e_woopayments_perf_probe_control' ]]; then
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
			if [[ "${PERF_FAKE_CASE:-}" == 'reference-version' ]]; then printf '11.0.0\n'; else printf '11.2.0\n'; fi
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

# The page every state renders, plus the payment tags a state prints on the checkout page.
# The canonical link names a provider word in a page URL and must not count as an asset; the native script
# source is root-relative and the stylesheet URL is entity-encoded, as WordPress prints them.
fake_page_body() {
	local state="$1" target="$2" origin='http://canonical.native.test:8187'
	local classes='page' content=''
	case "$target" in
	/) classes=home ;;
	'/?post_type=product') content='<header class="woocommerce-products-header">Shop</header>' ;;
	'/?product=canvas-tote') content='<div id="product-17">Canvas Tote</div>' ;;
	'/?page_id=6') content='<div data-block-name="woocommerce/cart"></div>' ;;
	'/?page_id=7') content='<div data-block-name="woocommerce/checkout"></div>' ;;
	esac
	if [[ "$target" == '/?post_type=product' ]]; then
		case "${PERF_FAKE_CASE:-}" in
		empty-body) return 0 ;;
		wrong-body) content='<div data-block-name="woocommerce/cart"></div>' ;;
		esac
	fi
	printf '<html><body class="%s wp-theme-%s"><main>%s</main>\n' "$classes" "$(< "${PERF_FAKE_ROOT:?}/theme")" "$content"
	if [[ "$target" == '/?post_type=product' && "${PERF_FAKE_CASE:-}" == truncated-body ]]; then return 0; fi
	if [[ "$target" == '/?page_id=7' || "$target" == '/?page_id=6' ]]; then
		local namespace=other
		if [[ "$state" == active_native && "${PERF_FAKE_CASE:-}" != missing-positive ]] || [[ "$state" == disabled && "${PERF_FAKE_CASE:-}" == dormant-wcpay-key ]]; then
			namespace=wcpay
		fi
		printf '%s\n' "<script id='wc-settings-js-before'>var wcSettings = JSON.parse( decodeURIComponent( '%7B%22extensions%22%3A%7B%22${namespace}%22%3A%7B%7D%7D%7D' ) );</script>"
	fi
	printf "<link rel='canonical' href='%s/product/wcpay-perf-product/' />\n" "$origin"
	printf '<script id="jquery-core-js" src="%s/wp-includes/js/jquery/jquery.min.js"></script>\n' "$origin"
	case "${PERF_FAKE_CASE:-}" in
	asset-available-fail) [[ "$target" != / || "$state" != available || "${3:-}" != 2 ]] || printf '<script>var wcpayConfig = {};</script>\n' ;;
	asset-native-fail) [[ "$target" != / || "$state" != disabled ]] || printf '<script id="wcpay-dormant-js" src="%s/wp-content/plugins/woocommerce/assets/js/dormant.js"></script>\n' "$origin" ;;
	esac
	case "$target:$state" in
	'/?page_id=7:active_native')
		printf "<link rel='stylesheet' id='wc-payment-method-woopayments-css' href='%s/wp-content/plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.css?ver=1&#038;rtl=0' media='all' />\n" "$origin"
		printf '<script id="wc-payment-method-woopayments-js-extra">var wcpayConfig = {"a":1};</script>\n'
		printf '<script id="wc-payment-method-woopayments-js" src="/wp-content/plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.js?ver=1"></script>\n'
		printf '<script id="stripe-js" src="https://js.stripe.com/v3/"></script>\n'
		;;
	'/?page_id=7:active_plugin')
		printf '<script id="WCPAY_BLOCKS_CHECKOUT-js" src="%s/wp-content/plugins/woocommerce-payments-reference/dist/blocks-checkout.js?ver=1"></script>\n' "$origin"
		printf '<script id="stripe-js" src="https://js.stripe.com/v3/"></script>\n'
		;;
	esac
	printf '</body></html>\n'
}

# Asset files are served by name: 300 bytes of stylesheet, 1000 of native script and 800 of plugin script.
fake_asset_body() {
	case "$1" in
	*/wc-payment-method-woopayments.css*) head -c 300 /dev/zero | tr '\0' 'c' ;;
	*/wc-payment-method-woopayments.js*) head -c 1000 /dev/zero | tr '\0' 'j' ;;
	*/blocks-checkout.js*) head -c 800 /dev/zero | tr '\0' 'p' ;;
	*/dormant.js) printf 'x' ;;
	*) exit 74 ;;
	esac
}

fake_curl() {
	local root="${PERF_FAKE_ROOT:?}" headers='' body='' cookie='' trace_header='' url='' state kind status=200 final_url queries memory hooks files=250 owner tier time_total=.100000 bootstrap=1 http=0 target="" gateway_first gateway_second repeat='' extra
	while (($#)); do
		case "$1" in
		-D) headers="$2"; shift 2 ;;
		-o) body="$2"; shift 2 ;;
		-b|-c) cookie="$2"; shift 2 ;;
		-H) trace_header="$2"; shift 2 ;;
		-w|--max-redirs) shift 2 ;;
		--fail|--fail-with-body|--location|--silent|--show-error) shift ;;
		*) url="$1"; shift ;;
		esac
	done
	state="$(< "$root/current-state")"
	kind="${PERF_COMPARE_SAMPLE_KIND:-preflight}"
	if [[ "$headers" =~ -r([0-9]+)\.headers$ ]]; then repeat="${BASH_REMATCH[1]}"; fi
	if [[ "$kind" != preflight ]]; then
		target="${url#http://canonical.native.test:8187}"
		case "$kind:$target" in
		asset:/wp-content/*|asset:/wp-includes/*) ;;
		asset:*) exit 72 ;;
		*:/|*:"/?add-to-cart=17&quantity=1"|*:"/?post_type=product"|*:"/?product=canvas-tote"|*:"/?page_id=6"|*:"/?page_id=7"|*:"/index.php?rest_route=/wc/store/v1/cart") ;;
		*) exit 72 ;;
		esac
	fi
	printf 'CURL\t%s\t%s\t%s\t%s\t%s\n' "$kind" "$state" "$url" "$cookie" "$trace_header" >> "$root/samples.log"
	if [[ "$kind" == asset ]]; then
		fake_asset_body "$target" > "$body"
		printf 'ASSET_FETCH\t%s\n' "$target" >> "$root/events.log"
		return
	fi
	final_url="$url"
	if [[ "$url" == *'add-to-cart=17'* ]]; then : > "$cookie.populated"; fi
	if [[ "$url" == *'rest_route=/wc/store/v1/cart'* ]]; then printf '{"items":[{"id":17}]}\n' > "$body"; fi
	if [[ -n "$body" && ! -s "$body" ]]; then fake_page_body "$state" "$target" "$repeat" > "$body"; fi

	if [[ -n "$headers" && "$kind" != preflight ]]; then
		case "$state" in
		baseline_noop) tier=noop; owner=native; queries=100; memory=1000000; hooks=100; gateway_first=2; gateway_second=0 ;;
		disabled|available|connected) tier="$state"; owner=native; queries=101; memory=1524288; hooks=105; gateway_first=3; gateway_second=0 ;;
		active_native) tier=active; owner=native; queries=202; memory=5097152; hooks=163; gateway_first=6; gateway_second=0 ;;
		active_plugin) tier=active; owner=plugin; queries=200; memory=3000000; hooks=160; gateway_first=5; gateway_second=0 ;;
		*) exit 71 ;;
		esac
		[[ "$state" == 'active_native' ]] && time_total=.105000
		case "${PERF_FAKE_CASE:-}" in
		query-fail) [[ "$state" != connected ]] || queries=203 ;;
		memory-fail) [[ "$state" != available ]] || memory=1524289 ;;
		hook-fail) [[ "$state" != disabled ]] || hooks=106 ;;
		timing-fail) [[ "$kind" != timing || "$state" != active_native ]] || time_total=.105001 ;;
		gateway-warm-fail) [[ "$state" != active_native ]] || gateway_second=1 ;;
		gateway-dormancy-fail) [[ "$state" != disabled ]] || gateway_first=4 ;;
		gateway-native-fail) [[ "$state" != active_native ]] || gateway_first=8 ;;
		gateway-native-boundary-pass) [[ "$state" != active_native ]] || gateway_first=7 ;;
		gateway-connected-boundary-pass) [[ "$state" != connected ]] || gateway_first=8 ;;
		gateway-connected-fail) [[ "$state" != connected ]] || gateway_first=9 ;;
		intermittent-query-recovers) [[ "$state" != disabled || "$kind" != primary-capture || "$repeat" != 1 ]] || queries=$((queries + 6)) ;;
		persistent-query-regression) [[ "$state" != disabled || "$kind" != primary-capture ]] || queries=$((queries + 6)) ;;
		missing-header) headers='' ;;
		malformed-header) tier='???' ;;
		invalid-files) files=unknown ;;
		wrong-owner) owner=plugin ;;
		wrong-bootstrap) bootstrap=2 ;;
		outbound-http) http=1 ;;
		wrong-final-page) final_url="${url%/}/wrong/" ;;
		http-failure) status=503 ;;
		esac
		# Every state sends the same header and cookie names with state-specific values, so the header
		# gate must compare names only. The header-* cases add or drop names on one side.
		extra="Set-Cookie: woocommerce_items_in_cart=$state-$kind; path=/\r\nX-Request-Id: $state-$kind-${repeat:-0}\r\nTransfer-Encoding: chunked\r\n"
		case "${PERF_FAKE_CASE:-}" in
		header-new-cookie) [[ "$state" != active_native || "$repeat" != 2 ]] || extra+="set-cookie: wcpay_native_session=abc; path=/\r\n" ;;
		header-new-name) [[ "$state" != active_native ]] || extra+="X-Native-Extra: 1\r\n" ;;
		header-population-cookie) [[ "$state" != disabled || "$kind" != population ]] || extra+="Set-Cookie: wcpay_dormant=1\r\n" ;;
		header-baseline-only) if [[ "$state" == baseline_noop ]]; then extra+="X-Baseline-Only: 1\r\nSet-Cookie: baseline_only=1\r\n"; else extra="${extra/Transfer-Encoding: chunked/Content-Length: 42}"; fi ;;
		esac
		if [[ -n "$headers" ]]; then
			if [[ "${PERF_FAKE_CASE:-}" == 'attribution-artifact-failure' && "$kind" == attribution ]]; then
				printf 'HTTP/1.1 %s OK\r\nX-WooCommerce-Native-Payments-Probe: error=attribution-artifacts\r\n\r\n' "$status" > "$headers"
			else
				printf 'HTTP/1.1 %s OK\r\nX-WooCommerce-Native-Payments-Probe: state=%s;tier=%s;owner=%s;bootstrap_calls=%s;queries=%s;used_peak_bytes=%s;hooks=%s;files=%s;http=%s;gateway_first_queries=%s;gateway_second_queries=%s\r\n%b\r\n' "$status" "$state" "$tier" "$owner" "$bootstrap" "$queries" "$memory" "$hooks" "$files" "$http" "$gateway_first" "$gateway_second" "$extra" > "$headers"
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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly RUNNER="$SCRIPT_DIR/perf-compare.sh"
readonly PROBE="$SCRIPT_DIR/perf-probe.php"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/perf-compare-test.XXXXXX")"
readonly SCRIPT_DIR PLUGIN_ROOT TEST_ROOT
trap 'rm -rf "$TEST_ROOT"' EXIT

fail() { echo "$1" >&2; exit 1; }

php -r '
define( "ABSPATH", __DIR__ );
$registered = array();
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return $value;
}
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
	"filter:woocommerce_load_payment_providers",
	"filter:pre_http_request",
	"action:plugins_loaded",
	"action:wp",
	"action:shutdown",
);
exit( $registered === $expected ? 0 : 1 );
' "$PROBE" || fail 'The MU probe did not register its measurement hooks.'

# T.12b: a WP-Cron run spawned mid-sample drains the snapshot's pending Action Scheduler work and makes the
# rest of that one sample cheaper, so the probe must keep WP-Cron off for every request it measures, even
# when the control option is not yet valid (the first WP-CLI calls after a database import).
for control_state in baseline_noop invalid; do
	php -r '
define( "ABSPATH", __DIR__ );
$control_state = $argv[2];
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return $value;
}
function get_option() {
	global $control_state;
	return "invalid" === $control_state ? null : array( "state" => $control_state, "reference_plugin_slug" => "woocommerce-payments-reference/woocommerce-payments.php" );
}
function add_filter() {}
function add_action() {}
require $argv[1];
exit( defined( "DISABLE_WP_CRON" ) && true === DISABLE_WP_CRON ? 0 : 1 );
' "$PROBE" "$control_state" || fail "The MU probe did not disable WP-Cron for a $control_state control."
done

probe_attribution_dir="$TEST_ROOT/probe-attribution"
mkdir -p "$probe_attribution_dir"
php -r '
define( "ABSPATH", __DIR__ );
define( "WP_PLUGIN_DIR", "/plugins" );
$registered_filters = array();
$registered_actions = array();
$upload_dir = $argv[2];
$_SERVER["HTTP_X_WOOCOMMERCE_NATIVE_PAYMENTS_PERF_TRACE"] = "baseline_noop";
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return $value;
}
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
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return $value;
}
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
global $e2e_woopayments_perf_probe;
exit( false === $method->invoke( $e2e_woopayments_perf_probe ) ? 0 : 1 );
' "$PROBE" || fail 'The MU probe did not report unavailable attribution artifact storage.'

assert_trace_is_rejected() {
	local trace_state="$1" control_state="$2"
	php -r '
define( "ABSPATH", __DIR__ );
$registered_filters = array();
$control_state = $argv[2];
$_SERVER["HTTP_X_WOOCOMMERCE_NATIVE_PAYMENTS_PERF_TRACE"] = $argv[3];
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return $value;
}
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
assert_trace_is_rejected active_native disabled

# install-reference given a local archive (--reference-zip) copies it to the temporary file the download would have
# written, checks it against the expected SHA-256 and extracts it. download_url() is never called, and the temporary
# copy is removed whether the check passes or refuses.
probe_reference_root="$TEST_ROOT/probe-reference"
probe_reference_zip="$probe_reference_root/mount/woocommerce-payments.zip"
mkdir -p "$probe_reference_root/wp/wp-admin/includes" "$probe_reference_root/mount"
printf '<?php\n' > "$probe_reference_root/wp/wp-admin/includes/file.php"
php -r '
$zip = new ZipArchive();
if ( true !== $zip->open( $argv[1], ZipArchive::CREATE ) ) {
	exit( 1 );
}
$zip->addFromString( "woocommerce-payments/woocommerce-payments.php", "<?php\n// Plugin Name: Reference fixture\n" );
exit( $zip->close() ? 0 : 1 );
' "$probe_reference_zip" || fail 'Could not build the reference zip fixture.'
probe_reference_sha="$(php -r 'echo hash_file( "sha256", $argv[1] );' "$probe_reference_zip")"

run_probe_install_reference() {
	local name="$1" expected_sha="$2" archive="$3" root="$probe_reference_root/$1"
	mkdir -p "$root/plugins" "$root/tmp"
	php -r '
define( "ABSPATH", $argv[1] . "/" );
define( "WP_PLUGIN_DIR", $argv[2] );
define( "WP_CLI", true );
$temp_dir = $argv[3];
$download_marker = $argv[4];
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_tempnam( $filename ) {
	global $temp_dir;
	$path = $temp_dir . "/" . basename( $filename, ".zip" ) . "-download.tmp";
	touch( $path );
	return $path;
}
function wp_delete_file( $file ) {
	@unlink( $file );
}
function is_wp_error( $thing ) {
	return false;
}
function download_url( $url ) {
	global $download_marker;
	touch( $download_marker );
	return $download_marker;
}
$args = array( "woocommerce-native-perf-helper", "install-reference", "https://github.com/Automattic/woocommerce-payments/releases/download/11.2.0/woocommerce-payments.zip", $argv[5], "woocommerce-payments-reference", $argv[6] );
require $argv[7];
' "$probe_reference_root/wp" "$root/plugins" "$root/tmp" "$root/downloaded" "$expected_sha" "$archive" "$PROBE" > "$root/stdout" 2> "$root/stderr"
}

assert_probe_reference_tidy() {
	local root="$probe_reference_root/$1"
	[[ ! -e "$root/downloaded" ]] || fail "install-reference downloaded the reference although a local archive was given ($1)."
	[[ -z "$(ls -A "$root/tmp")" ]] || fail "install-reference left its temporary archive copy ($1)."
	[[ ! -e "$root/plugins/woocommerce-payments-reference-stage" ]] || fail "install-reference left its staging folder ($1)."
	[[ -f "$probe_reference_zip" ]] || fail "install-reference moved or removed the local archive ($1)."
}

status=0; run_probe_install_reference local-zip "$probe_reference_sha" "$probe_reference_zip" || status=$?
[[ "$status" == 0 ]] || fail "install-reference refused a local archive with the expected SHA-256: $(< "$probe_reference_root/local-zip/stderr")"
[[ "$(< "$probe_reference_root/local-zip/stdout")" == "$probe_reference_sha" ]] || fail 'install-reference did not print the verified SHA-256 of the local archive.'
[[ -f "$probe_reference_root/local-zip/plugins/woocommerce-payments-reference/woocommerce-payments.php" ]] || fail 'install-reference did not install the local archive under the reference slug.'
assert_probe_reference_tidy local-zip

status=0; run_probe_install_reference local-zip-integrity '58ea000906c2fc6f40e9a79d5ea60ec3104ae6d5ddf4ae533c2162b3b5fe5f96' "$probe_reference_zip" || status=$?
[[ "$status" == 1 ]] || fail "install-reference did not refuse a local archive that is not the pinned zip (exit $status)."
grep -Fxq "reference-zip-integrity: $probe_reference_zip has SHA-256 $probe_reference_sha, not the pinned 58ea000906c2fc6f40e9a79d5ea60ec3104ae6d5ddf4ae533c2162b3b5fe5f96" "$probe_reference_root/local-zip-integrity/stderr" || fail 'The local archive refusal did not name both SHA-256 values.'
[[ ! -e "$probe_reference_root/local-zip-integrity/plugins/woocommerce-payments-reference" ]] || fail 'install-reference installed a local archive that failed the SHA-256 check.'
assert_probe_reference_tidy local-zip-integrity

status=0; run_probe_install_reference local-zip-missing "$probe_reference_sha" "$probe_reference_root/mount/missing.zip" || status=$?
[[ "$status" == 1 ]] || fail "Missing zip returned exit $status."
grep -Fxq "reference-zip-missing: $probe_reference_root/mount/missing.zip" "$probe_reference_root/local-zip-missing/stderr" || fail 'install-reference did not refuse a missing local archive.'
[[ ! -e "$probe_reference_root/local-zip-missing/plugins/woocommerce-payments-reference" ]] || fail 'install-reference installed something without its local archive.'
assert_probe_reference_tidy local-zip-missing


setup_case() {
	local name="$1" root="$TEST_ROOT/$1"
	mkdir -p "$root/bin" "$root/output" "$root/site/wp-content/plugins" "$root/site/wp-content/mu-plugins"
	: > "$root/commands.log"; : > "$root/events.log"; : > "$root/samples.log"; : > "$root/wp-env.json"
	printf 'default\n' > "$root/theme"
	printf 'false\n' > "$root/canonical-active"
	printf 'false\n' > "$root/reference-active"
	printf 'baseline_noop\n' > "$root/current-state"
	ln -s "$SCRIPT_DIR/perf-compare.test.sh" "$root/bin/pnpm"
	ln -s "$SCRIPT_DIR/perf-compare.test.sh" "$root/bin/curl"
	ln -s "$SCRIPT_DIR/perf-compare.test.sh" "$root/bin/date"
}

# The optional fifth argument is a zip the runner gets as --reference-zip.
run_case() {
	local name="$1" scenario="$2" expected="$3" mode="$4" reference_zip="${5:-}" root="$TEST_ROOT/$1" status=0 service
	local extra=()
	setup_case "$name"
	service="$([[ "$mode" == local ]] && echo tests-cli || echo cli)"
	if [[ -n "$reference_zip" ]]; then extra=(--reference-zip "$reference_zip"); fi
	PATH="$root/bin:$PATH" PERF_FAKE_ROOT="$root" PERF_FAKE_CASE="$scenario" PERF_EXPECTED_SERVICE="$service" PERF_FAKE_PLUGIN_ROOT="$PLUGIN_ROOT" PERF_FAKE_REFERENCE_ZIP="$reference_zip" \
		"$RUNNER" --mode "$mode" --store-url 'http://native.test:8187' --wp-env-config "$root/wp-env.json" --wp-env-service "$service" --output "$root/output/result.tsv" ${extra[@]+"${extra[@]}"} > "$root/stdout" 2> "$root/stderr" || status=$?
	[[ "$status" == "$expected" ]] || fail "$name expected exit $expected, got $status: $(< "$root/stderr")"
}

assert_cleaned() {
	local root="$1"
	[[ ! -e "$root/site/wp-content/mu-plugins/woopayments-native-perf-probe.php" ]] || fail "probe remained in $root"
	[[ ! -e "$root/site/wp-content/plugins/woocommerce-payments-reference" ]] || fail "reference remained in $root"
	if compgen -G "$root/site/.woocommerce-native-perf-*.sql" > /dev/null; then fail "database snapshot remained in $root"; fi
	[[ "$(< "$root/theme")" == default ]] || fail "Storefront remained active in $root"
	[[ "$(< "$root/canonical-active")" == false ]] || fail "canonical plugin changed in $root"
}

run_case local-pass pass 0 local
local_root="$TEST_ROOT/local-pass"; local_output="$local_root/output/result.tsv"
expected_account='{"data":{"account_id":"acct_native_ci","country":"US","default_currency":"usd","payments_enabled":true,"payouts_enabled":true,"details_submitted":true,"is_live":false,"test_publishable_key":"pk_test_native_ci","live_publishable_key":"","statement_descriptor":"NATIVE CI","statement_descriptor_kanji":"","statement_descriptor_kana":"","business_profile":{"name":"Native CI store","url":"https://example.test","support_address":{"country":"US"},"support_email":"support@example.test","support_phone":"+10000000000"},"branding":{"logo":"","icon":"","primary_color":"#000000","secondary_color":"#ffffff"},"communications_email":"owner@example.test","store_currencies":{"default":"usd"},"customer_currencies":{"supported":["usd","eur","aud","cad","chf","gbp","jpy","nzd","sek"]},"account_details":{"account_status":{"text":"Enabled"},"payout_status":{"text":"Enabled"},"banner":null},"deposits":{"interval":"daily","weekly_anchor":"monday","monthly_anchor":1,"delay_days":2,"status":"enabled","restrictions":"","completed_waiting_period":true},"platform_checkout_eligible":true,"capabilities":{"card_payments":"active","klarna_payments":"active"},"supported_payment_methods":["card","klarna"],"fees":{"card":[],"klarna":[]}},"fetched":1788898000,"errored":false,"consecutive_errors":0}'
[[ "$(grep -Fc $'ACCOUNT_DATA\t'"$expected_account" "$local_root/events.log")" == 29 ]] || fail 'Connected and active states did not use the complete seed-time account cache.'
[[ "$(grep -c $'^SEED_TIME\t1788898000$' "$local_root/events.log")" == 1 ]] || fail 'The account cache timestamp was not captured exactly once at seed time.'
[[ "$(wc -l < "$local_output" | tr -d ' ')" == 73 ]] || fail 'Local output is not the complete 30-row matrix plus the six gateway rows, four header rows, 30 asset rows, the asset control and timing row.'
[[ "$(grep -c $'^CURL\tprimary-warmup\t' "$local_root/samples.log")" == 90 ]] || fail 'Local matrix did not warm all six states across five routes for each of the three STATE_SAMPLES attempts.'
[[ "$(grep -c $'^CURL\tprimary-capture\t' "$local_root/samples.log")" == 90 ]] || fail 'Local matrix did not capture all six states across five routes for each of the three STATE_SAMPLES attempts.'
if awk -F '\t' '$2 != "preflight" && $4 !~ /^http:\/\/canonical.native.test:8187\// { bad=1 } END { exit bad ? 0 : 1 }' "$local_root/samples.log"; then fail 'Shopper requests did not share the service home origin and cookie scope.'; fi
for target in '/' '/?post_type=product' '/?product=canvas-tote' '/?page_id=6' '/?page_id=7'; do
	grep -Fq $'CURL\tprimary-capture\tbaseline_noop\thttp://canonical.native.test:8187'"$target"$'\t' "$local_root/samples.log" || fail "The canonical measured route was not used: $target"
done
[[ "$(grep -c $'^CURL\ttiming\t' "$local_root/samples.log")" == 18 ]] || fail 'Local timing did not run nine alternating pairs.'
[[ "$(awk -F '\t' '$2 == "population" && $4 ~ /wc\/store\/v1\/cart/ { count++ } END { print count + 0 }' "$local_root/samples.log")" == 40 ]] || fail 'Every state/timing/attribution sample did not prove a populated Store API cart.'
[[ "$(grep -c $'^CURL\tattribution\tbaseline_noop\t.*\tX-WooCommerce-Native-Payments-Perf-Trace: baseline_noop$' "$local_root/samples.log")" == 1 ]] || fail 'Local attribution did not trace baseline_noop exactly once.'
[[ "$(grep -c $'^CURL\tattribution\tdisabled\t.*\tX-WooCommerce-Native-Payments-Perf-Trace: disabled$' "$local_root/samples.log")" == 1 ]] || fail 'Local attribution did not trace disabled exactly once.'
[[ "$(grep -c $'^DB_EXPORT\t' "$local_root/events.log")" == 3 ]] || fail 'Expected original, seeded and restored database exports.'
[[ "$(grep -c $'^DB_IMPORT\t' "$local_root/events.log")" == 51 ]] || fail 'The disposable database reset count is wrong.'
[[ "$(grep -c $'^MULTI_CURRENCY_FEATURE\t"0"$' "$local_root/events.log")" == 40 ]] || fail 'Every local state preparation must disable Multi-Currency before native-tier measurement.'
[[ "$(grep -c $'^CORE_MULTI_CURRENCY_FEATURE\t"no"$' "$local_root/events.log")" == 40 ]] || fail 'Every local state preparation must disable the independent Core Multi-Currency feature before native-tier measurement.'
[[ "$(grep -c $'^WOOPAYMENTS_FEATURE\t' "$local_root/events.log")" == "$(grep -c $'^STATE\t' "$local_root/events.log")" ]] || fail 'Every local state preparation must write the WooPayments feature.'
awk -F'\t' '$1 == "WOOPAYMENTS_FEATURE" { want = ($2 == "baseline_noop" || $2 == "disabled") ? "\"no\"" : "\"yes\""; if ($3 != want) exit 1 }' "$local_root/events.log" || fail 'The WooPayments feature must be "no" for baseline_noop and disabled, and "yes" for every other local state.'
[[ "$(grep -c $'^REFERENCE_ACTIVATE$' "$local_root/events.log")" == 13 ]] || fail 'The isolated reference was not activated for its thirteen samples.'
[[ "$(grep -c $'^REFERENCE_DOWNLOAD\t' "$local_root/events.log")" == 1 && "$(grep -c '^REFERENCE_LOCAL' "$local_root/events.log")" == 0 ]] || fail 'Without --reference-zip the reference was not installed through its one download.'
awk -F '\t' '
	/^DB_EXPORT/ { if (++exports == 1) original=$2; if (exports == 2) seeded=$2; if (exports == 3 && $2 != original ".restored.sql") exit 1 }
	/^THEME_ACTIVE$/ { if (exports != 1) exit 1; theme=1 }
	/^SEED$/ { if (!theme) exit 1; seed=NR }
	/^SEED_TIME/ { if (!seed) exit 1; timestamp=NR }
	/^STORE_OPEN$/ { if (!timestamp) exit 1; open_store=NR }
	/^PRODUCT_READY$/ { if (!open_store || exports != 1) exit 1; ready=1 }
	/^DB_IMPORT/ { if (!ready || exports != 2 || ($2 != seeded && $2 != original)) exit 1; last_import=$2 }
	END { if (exports != 3 || !ready || original == seeded || last_import != original) exit 1 }
' "$local_root/events.log" || fail 'The original store and Storefront seed snapshots were used out of order.'
awk '/^STATE/{if(last !~ /^DB_IMPORT/) exit 1} {last=$0}' "$local_root/events.log" || fail 'A state was prepared without an immediately preceding database reset.'
awk '/^SAMPLE\tprimary-capture\tactive_plugin$/{if(++primary == 5) pending=1; next} /^SAMPLE\ttiming\tactive_plugin$/{pending=1; next} pending && /^DB_IMPORT/{pending=0; next} pending && /^(STATE|SAMPLE)/{exit 1} END{exit pending}' "$local_root/events.log" || fail 'A reference sample was not followed by a database reset.'
grep -Fq $'disabled\tfront\t101\t1524288\t105\tbaseline_noop\t1\t524288\t5\tpass' "$local_output" || fail 'The exact no-op ceiling did not pass.'
grep -Fq $'active_native\tfront\t202\t5097152\t163\tactive_plugin\t2\t2097152\t3\tpass' "$local_output" || fail 'The exact native/plugin ceiling did not pass.'
grep -Fq $'connected\tfront\t101\t1524288\t105\tactive_plugin\t-99\t-1475712\t-55\tpass' "$local_output" || fail 'Connected was not gated against the reference plugin.'
grep -Fq $'connected\tgateway\t3\t0\tNA\tactive_plugin\t-2\tNA\tNA\tpass' "$local_output" || fail 'The connected gateway row was not gated against the reference plugin.'
grep -Fq $'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\t105.000,100.000,5.000,pass' "$local_output" || fail 'The exact timing ceiling did not pass.'
grep -Fq $'baseline_noop\tgateway\t2\t0\tNA\tbaseline_noop\t0\tNA\tNA\tpass' "$local_output" || fail 'The exact baseline_noop gateway warm-zero row did not pass.'
grep -Fq $'disabled\tgateway\t3\t0\tNA\tbaseline_noop\t1\tNA\tNA\tpass' "$local_output" || fail 'The exact disabled gateway dormancy ceiling did not pass.'
grep -Fq $'active_native\tgateway\t6\t0\tNA\tactive_plugin\t1\tNA\tNA\tpass' "$local_output" || fail 'The exact active_native gateway ceiling did not pass.'
grep -Fq $'active_plugin\tgateway\t5\t0\tNA\tactive_plugin\t0\tNA\tNA\tinformational' "$local_output" || fail 'The exact active_plugin gateway row was not recorded as informational.'
for state in disabled available connected active_native; do
	grep -Fq "$state"$'\theaders\tNA\tNA\tNA\tbaseline_noop\t0\tNA\tNA\tpass' "$local_output" || fail "Identical header and cookie names with different values did not pass for $state."
done
if grep -q $'^active_plugin\theaders\t' "$local_output"; then fail 'The reference plugin state was header-gated.'; fi
# Counts two inline scripts, a stylesheet, a same-origin script and Stripe.js. Only local bytes enter the totals.
grep -Fq $'active_native\tassets:checkout\t5\t1433\t209\tNA\tNA\tNA\tNA\tinformational' "$local_output" || fail 'The active_native checkout asset row was not recorded.'
grep -Fq $'active_plugin\tassets:checkout\t2\t800\t28\tNA\tNA\tNA\tNA\tinformational' "$local_output" || fail 'The reference plugin checkout asset row was not recorded.'
grep -Fq $'active_native\tassets:front\t0\t0\t0\tNA\tNA\tNA\tNA\tinformational' "$local_output" || fail 'A canonical page link naming the provider was counted as an asset.'
for page in front shop product cart checkout; do
	grep -Fq $'disabled\tassets:'"$page"$'\t0\t0\t0\tNA\tNA\tNA\tNA\tpass' "$local_output" || fail "The disabled $page asset row did not pass."
done
grep -Fq $'available\tassets:checkout\t0\t0\t0\tNA\tNA\tNA\tNA\tpass' "$local_output" || fail 'The empty available asset row did not pass.'
grep -Fxq $'active_native\tassets:checkout_wcpay\t5\t1433\t209\tNA\tNA\tNA\tNA\tpass' "$local_output" || fail 'The active checkout did not find extensions.wcpay in wc-settings-js-before.'
[[ "$(grep -c $'^ASSET_FETCH\t' "$local_root/events.log")" == 3 ]] || fail 'Each same-origin asset file was not fetched exactly once.'
if grep -q $'^CURL\tasset\t[^\t]*\thttps://js.stripe.com' "$local_root/samples.log"; then fail 'A third-party asset was fetched.'; fi
assert_cleaned "$local_root"

# --reference-zip: the runner copies the zip into the plugin mount and install-reference reads that copy in place of
# the download. Nothing is downloaded, the run passes, and the copy is gone afterwards.
reference_fixture="$TEST_ROOT/reference-fixture.zip"
printf 'reference zip fixture\n' > "$reference_fixture"
run_case local-reference-zip pass 0 local "$reference_fixture"
reference_root="$TEST_ROOT/local-reference-zip"
[[ "$(grep -c '^REFERENCE_LOCAL' "$reference_root/events.log")" == 1 && "$(grep -c '^REFERENCE_DOWNLOAD' "$reference_root/events.log")" == 0 ]] || fail 'A --reference-zip run downloaded the reference or did not hand install-reference its copy.'
[[ "$(wc -l < "$reference_root/output/result.tsv" | tr -d ' ')" == 73 ]] || fail 'A --reference-zip run did not emit the complete local matrix.'
[[ "$(grep -c $'^REFERENCE_ACTIVATE$' "$reference_root/events.log")" == 13 ]] || fail 'A --reference-zip run did not activate the reference for its thirteen samples.'
if compgen -G "$PLUGIN_ROOT/tests/bin/tmp/woopayments-perf-reference.*" > /dev/null; then fail 'The --reference-zip copy remained in the plugin mount.'; fi
[[ "$(< "$reference_fixture")" == 'reference zip fixture' ]] || fail 'The --reference-zip source was changed or removed.'
assert_cleaned "$reference_root"

# T.12:a query count elevated on exactly one of the STATE_SAMPLES attempts (the intermittent
# component observed in CI) must not fail the run; the minimum across attempts recovers the
# clean value. A count elevated on every attempt (a genuine regression, not a flake) must still
# fail, so the minimum-of-N does not mask a real dormancy violation.
run_case intermittent-query-recovers intermittent-query-recovers 0 ci
intermittent_output="$TEST_ROOT/intermittent-query-recovers/output/result.tsv"
[[ "$(grep -c $'^CURL\tprimary-capture\t' "$TEST_ROOT/intermittent-query-recovers/samples.log")" == 45 ]] || fail 'CI did not attempt all three sampled states across five routes for each of the three STATE_SAMPLES attempts.'
grep -Fq $'disabled\tfront\t101\t1524288\t105\tbaseline_noop\t1\t524288\t5\tpass' "$intermittent_output" || fail 'A query count elevated on only one of three attempts was not recovered by the minimum.'
assert_cleaned "$TEST_ROOT/intermittent-query-recovers"

run_case persistent-query-regression persistent-query-regression 1 ci
persistent_output="$TEST_ROOT/persistent-query-regression/output/result.tsv"
grep -Fq $'disabled\tfront\t107\t1524288\t105\tbaseline_noop\t7\t524288\t5\tfail' "$persistent_output" || fail 'A query count elevated on every attempt was wrongly recovered by the minimum.'
assert_cleaned "$TEST_ROOT/persistent-query-regression"

run_case gateway-native-boundary-pass gateway-native-boundary-pass 0 local
boundary_output="$TEST_ROOT/gateway-native-boundary-pass/output/result.tsv"
[[ "$(wc -l < "$boundary_output" | tr -d ' ')" == 73 ]] || fail 'The active_native gateway boundary case did not emit a complete deterministic TSV.'
grep -Fq $'active_native\tgateway\t7\t0\tNA\tactive_plugin\t2\tNA\tNA\tpass' "$boundary_output" || fail 'The active_native gateway tolerance did not pass at exactly qd=2.'
assert_cleaned "$TEST_ROOT/gateway-native-boundary-pass"

run_case gateway-connected-boundary-pass gateway-connected-boundary-pass 0 local
connected_boundary_output="$TEST_ROOT/gateway-connected-boundary-pass/output/result.tsv"
grep -Fq $'connected\tgateway\t8\t0\tNA\tactive_plugin\t3\tNA\tNA\tpass' "$connected_boundary_output" || fail 'The connected gateway tolerance did not pass at exactly qd=3.'
assert_cleaned "$TEST_ROOT/gateway-connected-boundary-pass"

run_case attribution-artifact-failure attribution-artifact-failure 1 local
grep -Fq 'could not write required attribution artifacts' "$TEST_ROOT/attribution-artifact-failure/stderr" || fail 'Local attribution accepted a probe artifact-write failure.'
assert_cleaned "$TEST_ROOT/attribution-artifact-failure"

for failure in query-fail memory-fail hook-fail timing-fail gateway-warm-fail gateway-dormancy-fail gateway-native-fail gateway-connected-fail; do
	run_case "$failure" "$failure" 1 local
	[[ "$(wc -l < "$TEST_ROOT/$failure/output/result.tsv" | tr -d ' ')" == 73 ]] || fail "$failure did not emit a complete deterministic TSV."
	assert_cleaned "$TEST_ROOT/$failure"
done
grep -Fq $'connected\tfront\t203\t1524288\t105\tactive_plugin\t3\t-1475712\t-55\tfail' "$TEST_ROOT/query-fail/output/result.tsv" || fail 'Connected did not fail at exactly three queries over the reference plugin.'
grep -Fq $'active_native\tcheckout_median\tNA\tNA\tNA\tactive_plugin\tNA\tNA\tNA\t105.001,100.000,5.001,fail' "$TEST_ROOT/timing-fail/output/result.tsv" || fail 'The 5.001% timing boundary did not fail.'
grep -Fq $'active_native\tgateway\t6\t1\tNA\tactive_plugin\t1\tNA\tNA\tfail' "$TEST_ROOT/gateway-warm-fail/output/result.tsv" || fail 'A nonzero warm gateway call did not fail.'
grep -Fq $'disabled\tgateway\t4\t0\tNA\tbaseline_noop\t2\tNA\tNA\tfail' "$TEST_ROOT/gateway-dormancy-fail/output/result.tsv" || fail 'The disabled gateway dormancy tolerance did not fail at exactly qd=2.'
grep -Fq $'active_native\tgateway\t8\t0\tNA\tactive_plugin\t3\tNA\tNA\tfail' "$TEST_ROOT/gateway-native-fail/output/result.tsv" || fail 'The active_native gateway tolerance did not fail at exactly qd=3.'
grep -Fq $'connected\tgateway\t9\t0\tNA\tactive_plugin\t4\tNA\tNA\tfail' "$TEST_ROOT/gateway-connected-fail/output/result.tsv" || fail 'The connected gateway tolerance did not fail at exactly qd=4.'

for invalid in missing-header malformed-header invalid-files wrong-owner wrong-bootstrap outbound-http wrong-final-page http-failure; do
	run_case "$invalid" "$invalid" 1 ci
	grep -Eq 'Invalid (warm-up|capture|populated session)' "$TEST_ROOT/$invalid/stderr" || fail "$invalid lacked a precise invalid-sample error."
	assert_cleaned "$TEST_ROOT/$invalid"
done
grep -Fq 'observed 1 outbound HTTP requests' "$TEST_ROOT/outbound-http/stderr" || fail 'A nonzero measured HTTP count lacked a precise invalid-sample error.'

run_case ci-pass pass 0 ci
ci_root="$TEST_ROOT/ci-pass"; ci_output="$ci_root/output/result.tsv"
[[ "$(wc -l < "$ci_output" | tr -d ' ')" == 37 ]] || fail 'CI output is not the complete three-state subset plus its three gateway, two header and 16 asset rows.'
[[ "$(awk -F '\t' 'NR > 1 { states[$1]=1 } END { for (state in states) print state }' "$ci_output" | sort | tr '\n' ' ')" == 'active_native baseline_noop disabled ' ]] || fail 'CI sampled the wrong states.'
grep -Fq $'baseline_noop\tgateway\t2\t0\tNA\tbaseline_noop\t0\tNA\tNA\tpass' "$ci_output" || fail 'The exact CI baseline_noop gateway row did not pass.'
grep -Fq $'disabled\tgateway\t3\t0\tNA\tbaseline_noop\t1\tNA\tNA\tpass' "$ci_output" || fail 'The exact CI disabled gateway row did not pass.'
grep -Fq $'active_native\tgateway\t6\t0\tNA\tnot_evaluated\tNA\tNA\tNA\tnot_evaluated' "$ci_output" || fail 'CI did not keep active_native gateway not_evaluated.'
[[ "$(grep -c $'^DB_EXPORT\t' "$ci_root/events.log")" == 3 && "$(grep -c $'^DB_IMPORT\t' "$ci_root/events.log")" == 10 ]] || fail 'CI did not use three exports and ten imports (three STATE_SAMPLES attempts per sampled state, plus the final cleanup restore).'
[[ "$(grep -c $'^MULTI_CURRENCY_FEATURE\t"0"$' "$ci_root/events.log")" == 9 ]] || fail 'Every CI state preparation attempt must disable Multi-Currency before native-tier measurement.'
[[ "$(grep -c $'^WOOPAYMENTS_FEATURE\t' "$ci_root/events.log")" == "$(grep -c $'^STATE\t' "$ci_root/events.log")" ]] || fail 'Every CI state preparation must write the WooPayments feature.'
awk -F'\t' '$1 == "WOOPAYMENTS_FEATURE" { want = ($2 == "baseline_noop" || $2 == "disabled") ? "\"no\"" : "\"yes\""; if ($3 != want) exit 1 }' "$ci_root/events.log" || fail 'The WooPayments feature must be "no" for baseline_noop and disabled, and "yes" for every other CI state.'
if grep -Eq '^REFERENCE_(INSTALL|ACTIVATE)' "$ci_root/events.log"; then fail 'CI touched the reference plugin.'; fi
grep -Fq $'active_native\tassets:checkout\t5\t1433\t209\tNA\tNA\tNA\tNA\tinformational' "$ci_output" || fail 'CI did not record the active_native checkout assets.'
grep -Fq $'disabled\tassets:checkout\t0\t0\t0\tNA\tNA\tNA\tNA\tpass' "$ci_output" || fail 'The CI disabled checkout asset row did not pass.'
assert_cleaned "$ci_root"

# N-299: a disabled store's shop page may print no native tag.
run_case asset-native-fail asset-native-fail 1 ci
grep -Fq $'disabled\tassets:front\t1\t1\t21\tNA\tNA\tNA\tNA\tfail' "$TEST_ROOT/asset-native-fail/output/result.tsv" || fail 'A native tag on the disabled front page did not fail the asset gate.'
assert_cleaned "$TEST_ROOT/asset-native-fail"

for invalid in empty-body wrong-body truncated-body; do
	run_case "$invalid" "$invalid" 1 ci
	expected='missing Storefront markup for shop'
	if [[ "$invalid" == empty-body ]]; then expected='empty response body'; fi
	grep -Fq "Invalid warm-up-r1 (baseline_noop/shop): $expected." "$TEST_ROOT/$invalid/stderr" || fail "$invalid did not identify the invalid shop response."
	assert_cleaned "$TEST_ROOT/$invalid"
done

printf '<html><body class="home wp-theme-storefront"><main></main></body></html>\n' > "$TEST_ROOT/complete.html"
if bash -c 'source "$1"; validate_page_body "$2" unknown sample' _ "$RUNNER" "$TEST_ROOT/complete.html" 2> "$TEST_ROOT/unknown-page.stderr"; then
	fail 'The page check accepted an unknown page name.'
fi
grep -Fxq 'Invalid sample: unknown page name: unknown.' "$TEST_ROOT/unknown-page.stderr" || fail 'The page check did not identify the unknown page.'

run_case missing-positive missing-positive 1 ci
grep -Fxq $'active_native\tassets:checkout_wcpay\t4\t1326\t100\tNA\tNA\tNA\tNA\tfail' "$TEST_ROOT/missing-positive/output/result.tsv" || fail 'The four other checkout tags must not satisfy the extensions.wcpay control.'
assert_cleaned "$TEST_ROOT/missing-positive"

run_case dormant-wcpay-key dormant-wcpay-key 1 ci
for page in cart checkout; do
	grep -Fxq $'disabled\tassets:'"$page"$'\t1\t107\t109\tNA\tNA\tNA\tNA\tfail' "$TEST_ROOT/dormant-wcpay-key/output/result.tsv" || fail "The disabled $page printed extensions.wcpay without failing."
done
assert_cleaned "$TEST_ROOT/dormant-wcpay-key"

run_case asset-available-fail asset-available-fail 1 local
grep -Eq '^available[[:space:]]+assets:front[[:space:]]+1[[:space:]].*fail$' "$TEST_ROOT/asset-available-fail/output/result.tsv" || fail 'The lowest enabled tier printed inline payment configuration without failing.'
assert_cleaned "$TEST_ROOT/asset-available-fail"

run_case restore-mismatch restore-mismatch 1 ci
grep -Fq 'Original export retained' "$TEST_ROOT/restore-mismatch/stderr" || fail 'The database restore mismatch went unreported.'
compgen -G "$TEST_ROOT/restore-mismatch/site/.woocommerce-native-perf-*-original.sql" > /dev/null || fail 'The original export was lost after a restore mismatch.'

# N-139: a native state must not set a cookie name or send a header name baseline_noop does not.
run_case header-new-cookie header-new-cookie 1 ci
grep -Fq $'active_native\theaders\tNA\tNA\tNA\tbaseline_noop\t7\tNA\tNA\tfail' "$TEST_ROOT/header-new-cookie/output/result.tsv" || fail 'A cookie active_native sets on one attempt only did not fail the header gate.'
grep -Fq 'Header gate (active_native/front): not sent by baseline_noop: cookie:wcpay_native_session' "$TEST_ROOT/header-new-cookie/stderr" || fail 'The header gate did not name the added cookie.'
grep -Fq $'disabled\theaders\tNA\tNA\tNA\tbaseline_noop\t0\tNA\tNA\tpass' "$TEST_ROOT/header-new-cookie/output/result.tsv" || fail 'A state without added names did not pass the header gate.'
assert_cleaned "$TEST_ROOT/header-new-cookie"

run_case header-new-name header-new-name 1 ci
grep -Fq $'active_native\theaders\tNA\tNA\tNA\tbaseline_noop\t7\tNA\tNA\tfail' "$TEST_ROOT/header-new-name/output/result.tsv" || fail 'A header name only active_native sends did not fail the header gate.'
grep -Fq 'Header gate (active_native/checkout): not sent by baseline_noop: header:x-native-extra' "$TEST_ROOT/header-new-name/stderr" || fail 'The header gate did not name the added header.'

run_case header-population-cookie header-population-cookie 1 ci
grep -Fq $'disabled\theaders\tNA\tNA\tNA\tbaseline_noop\t4\tNA\tNA\tfail' "$TEST_ROOT/header-population-cookie/output/result.tsv" || fail 'A cookie a dormant state sets only while the session is populated did not fail the header gate.'
grep -Fq 'Header gate (disabled/add_to_cart): not sent by baseline_noop: cookie:wcpay_dormant' "$TEST_ROOT/header-population-cookie/stderr" || fail 'The header gate did not name the population-request cookie.'

run_case header-baseline-only header-baseline-only 0 ci
grep -Fq $'active_native\theaders\tNA\tNA\tNA\tbaseline_noop\t0\tNA\tNA\tpass' "$TEST_ROOT/header-baseline-only/output/result.tsv" || fail 'Names only baseline_noop sends, or a framing-header swap, failed the header gate.'
assert_cleaned "$TEST_ROOT/header-baseline-only"

run_case gateway-warm-fail-ci gateway-warm-fail 1 ci
gateway_warm_fail_ci_output="$TEST_ROOT/gateway-warm-fail-ci/output/result.tsv"
[[ "$(wc -l < "$gateway_warm_fail_ci_output" | tr -d ' ')" == 37 ]] || fail 'CI gateway-warm-fail did not emit a complete deterministic TSV.'
grep -Fq $'active_native\tgateway\t6\t1\tNA\tnot_evaluated\tNA\tNA\tNA\tfail' "$gateway_warm_fail_ci_output" || fail 'CI active_native gateway did not fail on a nonzero warm call with no reference state evaluated.'
assert_cleaned "$TEST_ROOT/gateway-warm-fail-ci"

for failure in export-failure import-failure reference-integrity reference-version reference-remove-failure; do
	run_case "$failure" "$failure" 1 local
done
[[ "$(grep -c $'^DB_EXPORT\t' "$TEST_ROOT/export-failure/events.log")" == 1 && "$(grep -c $'^SAMPLE\t' "$TEST_ROOT/export-failure/events.log")" == 0 ]] || fail 'Export failure reached sampling.'
grep -Fq $'DB_IMPORT\t' "$TEST_ROOT/import-failure/events.log" || fail 'Import failure was not propagated from a reset attempt.'
grep -Fq '58ea000906c2fc6f40e9a79d5ea60ec3104ae6d5ddf4ae533c2162b3b5fe5f96' "$TEST_ROOT/reference-integrity/events.log" || fail 'Reference integrity did not use the pinned digest.'
grep -Fq 'Cleanup failed' "$TEST_ROOT/reference-remove-failure/stderr" || fail 'Reference cleanup failure did not propagate.'

for arguments in '--mode' '--store-url' '--output' '--wp-env-config' '--wp-env-service' '--reference-zip' '--reference-zip= --output result.tsv' '--mode unsupported --output result.tsv' '--reference-zip /nonexistent/reference.zip --output result.tsv'; do
	name="argument-$(printf '%s' "$arguments" | tr ' /' '__')"; setup_case "$name"; root="$TEST_ROOT/$name"; status=0
	# Deliberately split malformed argument vectors.
	# shellcheck disable=SC2086
	PATH="$root/bin:$PATH" PERF_FAKE_ROOT="$root" "$RUNNER" $arguments > "$root/stdout" 2> "$root/stderr" || status=$?
	[[ "$status" == 2 && ! -s "$root/commands.log" ]] || fail "Argument contract failed without isolation for: $arguments"
done

# An empty --reference-zip operand is a usage error in both modes, not the option left out (which downloads).
for mode in local ci; do
	name="argument-empty-reference-zip-$mode"; setup_case "$name"; root="$TEST_ROOT/$name"; status=0
	PATH="$root/bin:$PATH" PERF_FAKE_ROOT="$root" "$RUNNER" --mode "$mode" --reference-zip '' --output result.tsv > "$root/stdout" 2> "$root/stderr" || status=$?
	[[ "$status" == 2 && ! -s "$root/commands.log" ]] || fail "Empty zip reached $mode mode."
	grep -Fxq -- '--reference-zip requires a non-empty operand' "$root/stderr" || fail "An empty --reference-zip operand was not refused at parsing in $mode mode."
done

# CI mode refuses --reference-zip for the mode itself: the zip exists, so only the mode check can refuse it.
[[ -f "$probe_reference_zip" ]] || fail 'The zip fixture for the CI-mode --reference-zip case is missing.'
name='argument-ci-reference-zip'; setup_case "$name"; root="$TEST_ROOT/$name"; status=0
PATH="$root/bin:$PATH" PERF_FAKE_ROOT="$root" "$RUNNER" --mode ci --reference-zip "$probe_reference_zip" --output result.tsv > "$root/stdout" 2> "$root/stderr" || status=$?
[[ "$status" == 2 && ! -s "$root/commands.log" ]] || fail 'The local zip reached CI mode.'
grep -Fxq -- '--reference-zip applies only to --mode local' "$root/stderr" || fail 'CI mode accepted --reference-zip with an existing zip.'

echo 'perf-compare.sh tests passed.'
