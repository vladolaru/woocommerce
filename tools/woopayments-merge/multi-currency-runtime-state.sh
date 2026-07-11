#!/usr/bin/env bash

# Reusable transactional state handling for mutating multi-currency harness gates.

if [ -n "${WOOPAYMENTS_MC_RUNTIME_STATE_LOADED:-}" ]; then
	return 0
fi
WOOPAYMENTS_MC_RUNTIME_STATE_LOADED=1

woopayments_mc_last_json_line() {
	grep -E '^\{' | tail -1
}

woopayments_mc_validate_json_object() {
	python3 -c '
import json
import sys

value = json.load(sys.stdin)
if not isinstance(value, dict):
    raise SystemExit(1)
'
}

woopayments_mc_wp_eval_json() {
	local wp_cmd="$1"
	local label="$2"
	local php="$3"
	local raw rc json

	# WP runner strings intentionally follow the harness's "docker exec ... wp" convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval "$php" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | woopayments_mc_last_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'FAIL: %s WP state probe failed.\n' "$label" >&2
		printf '%s\n' "$raw" | tail -40 >&2
		return 1
	fi

	if ! printf '%s\n' "$json" | woopayments_mc_validate_json_object >/dev/null 2>&1; then
		printf 'FAIL: %s WP state probe returned invalid JSON.\n' "$label" >&2
		return 1
	fi
	printf '%s\n' "$json"
}

woopayments_mc_wp_eval_json_stdin() {
	local wp_cmd="$1"
	local label="$2"
	local php="$3"
	local input_file="$4"
	local raw rc json

	# WP runner strings intentionally follow the harness's "docker exec ... wp" convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval "$php" < "$input_file" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | woopayments_mc_last_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'FAIL: %s WP state probe failed.\n' "$label" >&2
		printf '%s\n' "$raw" | tail -40 >&2
		return 1
	fi

	if ! printf '%s\n' "$json" | woopayments_mc_validate_json_object >/dev/null 2>&1; then
		printf 'FAIL: %s WP state probe returned invalid JSON.\n' "$label" >&2
		return 1
	fi
	printf '%s\n' "$json"
}

woopayments_mc_normalize_currencies() {
	python3 - "$1" <<'PY'
import sys

currencies = []
seen = set()
for raw in sys.argv[1].split(","):
    currency = raw.strip().upper()
    if not currency:
        continue
    if len(currency) != 3 or not currency.isalpha():
        raise SystemExit(1)
    if currency not in seen:
        currencies.append(currency)
        seen.add(currency)
if not currencies:
    raise SystemExit(1)
print(",".join(currencies))
PY
}

woopayments_mc_php_array_literal() {
	python3 - "$1" <<'PY'
import sys

currencies = [currency for currency in sys.argv[1].split(",") if currency]
print("array( " + ", ".join(repr(currency) for currency in currencies) + " )")
PY
}

woopayments_mc_expected_option_names_json() {
	python3 - "$1" <<'PY'
import json
import sys

option_names = [
    "woocommerce_currency",
    "_wcpay_feature_customer_multi_currency",
    "wcpay_multi_currency_setup_completed",
    "wcpay_multi_currency_enabled_currencies",
    "wcpay_multi_currency_cached_currencies",
    "_transient_wcpay_currency_format",
    "_transient_timeout_wcpay_currency_format",
    "_transient_wcpay_locale_info",
    "_transient_timeout_wcpay_locale_info",
]
for currency in (item.lower() for item in sys.argv[1].split(",") if item):
    option_names.extend(
        [
            f"wcpay_multi_currency_exchange_rate_{currency}",
            f"wcpay_multi_currency_manual_rate_{currency}",
            f"wcpay_multi_currency_price_rounding_{currency}",
            f"wcpay_multi_currency_price_charm_{currency}",
        ]
    )
print(json.dumps(option_names, separators=(",", ":")))
PY
}

woopayments_mc_snapshot_options() {
	local wp_cmd="$1"
	local role="$2"
	local currencies_csv="$3"
	local currencies_php snapshot_php

	currencies_php="$(woopayments_mc_php_array_literal "$currencies_csv")" || return 1
	snapshot_php="$(cat <<PHP
/* woopayments_mc_state_snapshot */
\$role = '$role';
\$currencies_to = $currencies_php;
\$option_names = array(
	'woocommerce_currency',
	'_wcpay_feature_customer_multi_currency',
	'wcpay_multi_currency_setup_completed',
	'wcpay_multi_currency_enabled_currencies',
	'wcpay_multi_currency_cached_currencies',
	'_transient_wcpay_currency_format',
	'_transient_timeout_wcpay_currency_format',
	'_transient_wcpay_locale_info',
	'_transient_timeout_wcpay_locale_info',
);
foreach ( \$currencies_to as \$currency_code ) {
	\$currency_lc = strtolower( (string) \$currency_code );
	\$option_names[] = 'wcpay_multi_currency_exchange_rate_' . \$currency_lc;
	\$option_names[] = 'wcpay_multi_currency_manual_rate_' . \$currency_lc;
	\$option_names[] = 'wcpay_multi_currency_price_rounding_' . \$currency_lc;
	\$option_names[] = 'wcpay_multi_currency_price_charm_' . \$currency_lc;
}
\$option_names = array_values( array_unique( \$option_names ) );
\$snapshot_options = array();
global \$wpdb;
foreach ( \$option_names as \$option_name ) {
	\$wpdb->last_error = '';
	\$row = \$wpdb->get_row(
		\$wpdb->prepare(
			"SELECT option_value, autoload FROM {\$wpdb->options} WHERE option_name = %s LIMIT 1",
			\$option_name
		),
		ARRAY_A
	);
	if ( '' !== (string) \$wpdb->last_error ) {
		throw new RuntimeException( 'snapshot_read_failed:' . \$option_name );
	}
	if ( null === \$row ) {
		\$snapshot_options[ \$option_name ] = array( 'exists' => false );
		continue;
	}
	\$snapshot_options[ \$option_name ] = array(
		'exists' => true,
		'option_value_b64' => base64_encode( (string) \$row['option_value'] ),
		'autoload' => (string) \$row['autoload'],
	);
}
\$snapshot_payload = array(
	'schema' => 'woopayments_mc_option_snapshot_payload.v1',
	'option_names' => \$option_names,
	'options' => \$snapshot_options,
);
\$snapshot_payload_json = wp_json_encode( \$snapshot_payload, JSON_UNESCAPED_SLASHES );
WP_CLI::line( wp_json_encode( array(
	'schema' => 'woopayments_mc_option_snapshot.v1',
	'role' => \$role,
	'snapshot_b64' => base64_encode( \$snapshot_payload_json ),
	'snapshot_sha256' => hash( 'sha256', \$snapshot_payload_json ),
	'option_names' => \$option_names,
	'option_count' => count( \$option_names ),
) ) );
PHP
)"

	woopayments_mc_wp_eval_json "$wp_cmd" "$role option snapshot" "$snapshot_php"
}

woopayments_mc_validate_snapshot_file() {
	local snapshot_file="$1"
	local role="$2"
	local currencies_csv="$3"
	local expected_names_json

	expected_names_json="$(woopayments_mc_expected_option_names_json "$currencies_csv")" || return 1
	python3 - "$snapshot_file" "$role" "$expected_names_json" <<'PY'
import base64
import hashlib
import json
import sys
from pathlib import Path

snapshot = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
role = sys.argv[2]
expected_names = json.loads(sys.argv[3])

if snapshot.get("schema") != "woopayments_mc_option_snapshot.v1":
    raise SystemExit("snapshot schema mismatch")
if snapshot.get("role") != role:
    raise SystemExit("snapshot role mismatch")
if snapshot.get("option_names") != expected_names:
    raise SystemExit("snapshot option inventory mismatch")
if snapshot.get("option_count") != len(expected_names):
    raise SystemExit("snapshot option count mismatch")
try:
    payload_bytes = base64.b64decode(snapshot["snapshot_b64"], validate=True)
except Exception as error:
    raise SystemExit(f"invalid snapshot payload: {error}")
if hashlib.sha256(payload_bytes).hexdigest() != snapshot.get("snapshot_sha256"):
    raise SystemExit("snapshot digest mismatch")
payload = json.loads(payload_bytes)
if payload.get("schema") != "woopayments_mc_option_snapshot_payload.v1":
    raise SystemExit("snapshot payload schema mismatch")
if payload.get("option_names") != expected_names:
    raise SystemExit("snapshot payload option inventory mismatch")
options = payload.get("options")
if not isinstance(options, dict) or set(options) != set(expected_names):
    raise SystemExit("snapshot option records mismatch")
for option_name in expected_names:
    record = options[option_name]
    if not isinstance(record, dict) or not isinstance(record.get("exists"), bool):
        raise SystemExit(f"invalid snapshot record for {option_name}")
    if record["exists"]:
        if not isinstance(record.get("autoload"), str):
            raise SystemExit(f"missing autoload value for {option_name}")
        try:
            base64.b64decode(record["option_value_b64"], validate=True)
        except Exception as error:
            raise SystemExit(f"invalid option value for {option_name}: {error}")
PY
}

woopayments_mc_configure_automatic() {
	local wp_cmd="$1"
	local role="$2"
	local runtime="$3"
	local currencies_csv="$4"
	local currencies_php configure_php

	currencies_php="$(woopayments_mc_php_array_literal "$currencies_csv")" || return 1
	configure_php="$(cat <<PHP
/* woopayments_mc_state_configure_automatic */
\$role = '$role';
\$runtime = '$runtime';
\$currencies_to = $currencies_php;
\$store_currency = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
if ( ! preg_match( '/^[A-Z]{3}$/', \$store_currency ) || in_array( \$store_currency, \$currencies_to, true ) ) {
	WP_CLI::line( wp_json_encode( array(
		'schema' => 'woopayments_mc_automatic_configure.v1',
		'role' => \$role,
		'runtime' => \$runtime,
		'configured' => false,
		'error_code' => 'invalid_or_non_converted_store_currency',
		'store_currency' => \$store_currency,
		'currencies_to' => \$currencies_to,
	) ) );
	return;
}

update_option( '_wcpay_feature_customer_multi_currency', '1', false );
update_option( 'wcpay_multi_currency_setup_completed', true, false );
update_option( 'wcpay_multi_currency_enabled_currencies', \$currencies_to, false );
foreach ( \$currencies_to as \$currency_code ) {
	\$currency_lc = strtolower( (string) \$currency_code );
	update_option( 'wcpay_multi_currency_exchange_rate_' . \$currency_lc, 'automatic', false );
	delete_option( 'wcpay_multi_currency_manual_rate_' . \$currency_lc );
	update_option( 'wcpay_multi_currency_price_rounding_' . \$currency_lc, '0', false );
	update_option( 'wcpay_multi_currency_price_charm_' . \$currency_lc, '0', false );
}

\$cache_key = 'wcpay_multi_currency_cached_currencies';
\$refresh_started_at = time();
\$missing_marker = new stdClass();
\$cache_before = get_option( \$cache_key, \$missing_marker );
\$cache_was_present = \$missing_marker !== \$cache_before;
\$cache_delete_result = delete_option( \$cache_key );
wp_cache_delete( \$cache_key, 'options' );
\$cache_after = get_option( \$cache_key, \$missing_marker );
\$cache_absent_after_delete = \$missing_marker === \$cache_after;
\$rate_modes = array();
\$manual_rates_absent = true;
\$missing_option_marker = new stdClass();
foreach ( \$currencies_to as \$currency_code ) {
	\$currency_lc = strtolower( (string) \$currency_code );
	\$rate_modes[ \$currency_code ] = (string) get_option( 'wcpay_multi_currency_exchange_rate_' . \$currency_lc, '' );
	if ( \$missing_option_marker !== get_option( 'wcpay_multi_currency_manual_rate_' . \$currency_lc, \$missing_option_marker ) ) {
		\$manual_rates_absent = false;
	}
}
\$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
\$shared_options_verified = (
	'1' === (string) get_option( '_wcpay_feature_customer_multi_currency', '' )
	&& (bool) get_option( 'wcpay_multi_currency_setup_completed', false )
	&& is_array( \$enabled_currencies )
	&& array_values( \$enabled_currencies ) === array_values( \$currencies_to )
);
WP_CLI::line( wp_json_encode( array(
	'schema' => 'woopayments_mc_automatic_configure.v1',
	'role' => \$role,
	'runtime' => \$runtime,
	'configured' => true,
	'store_currency' => \$store_currency,
	'currencies_to' => \$currencies_to,
	'enabled_currencies' => \$enabled_currencies,
	'rate_modes' => \$rate_modes,
	'manual_rates_absent' => \$manual_rates_absent,
	'shared_options_verified' => \$shared_options_verified,
	'refresh_started_at' => \$refresh_started_at,
	'cache_was_present' => \$cache_was_present,
	'cache_delete_result' => \$cache_delete_result,
	'cache_absent_after_delete' => \$cache_absent_after_delete,
) ) );
PHP
)"

	woopayments_mc_wp_eval_json "$wp_cmd" "$role automatic-rate configuration" "$configure_php"
}

woopayments_mc_build_runtime_state() {
	local wp_cmd="$1"
	local role="$2"
	local runtime="$3"
	local build_php

	build_php="$(cat <<PHP
/* woopayments_mc_state_build */
\$role = '$role';
\$runtime = '$runtime';
\$provider_id = '';
\$provider_registered = false;
\$provider_available = false;
\$state_built = false;
\$error_code = '';
try {
	if ( 'native' === \$runtime ) {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Internal\\MultiCurrency\\Services\\MultiCurrencyStateBuilderFactory' ) ) {
			throw new RuntimeException( 'native_multi_currency_runtime_unavailable' );
		}
		\$registry = wc_get_container()->get( \\Automattic\\WooCommerce\\Internal\\MultiCurrency\\Providers\\CurrencyRateProviderRegistryFactory::class )->create();
		\$registered_provider = \$registry->get_provider( 'woopayments' );
		\$provider_registered = null !== \$registered_provider;
		\$provider_available = \$registered_provider ? (bool) \$registered_provider->is_available() : false;
		\$provider = \$registry->get_available_provider();
		\$provider_id = \$provider ? (string) \$provider->get_id() : '';
		wc_get_container()->get( \\Automattic\\WooCommerce\\Internal\\MultiCurrency\\Services\\MultiCurrencyStateBuilderFactory::class )->create()->build();
		\$state_built = true;
	} elseif ( 'plugin' === \$runtime ) {
		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
			throw new RuntimeException( 'plugin_multi_currency_runtime_unavailable' );
		}
		\$multi_currency = WC_Payments_Multi_Currency();
		if ( ! \$multi_currency || ! method_exists( \$multi_currency, 'get_cached_currencies' ) ) {
			throw new RuntimeException( 'plugin_multi_currency_state_builder_unavailable' );
		}
		\$multi_currency->get_cached_currencies();
		\$provider_id = 'woopayments';
		\$provider_registered = true;
		\$provider_available = true;
		\$state_built = true;
	} else {
		throw new RuntimeException( 'unknown_multi_currency_runtime' );
	}
} catch ( Throwable \$error ) {
	\$error_code = substr( \$error->getMessage(), 0, 160 );
}
WP_CLI::line( wp_json_encode( array(
	'schema' => 'woopayments_mc_runtime_build.v1',
	'role' => \$role,
	'runtime' => \$runtime,
	'provider' => \$provider_id,
	'provider_registered' => \$provider_registered,
	'provider_available' => \$provider_available,
	'state_built' => \$state_built,
	'built_at' => time(),
	'error_code' => \$error_code,
) ) );
PHP
)"

	woopayments_mc_wp_eval_json "$wp_cmd" "$role runtime state build" "$build_php"
}

woopayments_mc_inspect_rates() {
	local wp_cmd="$1"
	local role="$2"
	local runtime="$3"
	local currencies_csv="$4"
	local currencies_php inspect_php

	currencies_php="$(woopayments_mc_php_array_literal "$currencies_csv")" || return 1
	inspect_php="$(cat <<PHP
/* woopayments_mc_state_inspect */
\$role = '$role';
\$runtime = '$runtime';
\$provider_id = '';
\$provider_registered = false;
\$provider_available = false;
if ( 'native' === \$runtime && class_exists( '\\Automattic\\WooCommerce\\Internal\\MultiCurrency\\Services\\MultiCurrencyStateBuilderFactory' ) ) {
	\$registry = wc_get_container()->get( \\Automattic\\WooCommerce\\Internal\\MultiCurrency\\Providers\\CurrencyRateProviderRegistryFactory::class )->create();
	\$registered_provider = \$registry->get_provider( 'woopayments' );
	\$provider_registered = null !== \$registered_provider;
	\$provider_available = \$registered_provider ? (bool) \$registered_provider->is_available() : false;
	\$provider = \$registry->get_available_provider();
	\$provider_id = \$provider ? (string) \$provider->get_id() : '';
} elseif ( 'plugin' === \$runtime && function_exists( 'WC_Payments_Multi_Currency' ) ) {
	\$provider_id = 'woopayments';
	\$provider_registered = true;
	\$provider_available = true;
}
\$raw_cache = get_option( 'wcpay_multi_currency_cached_currencies', array() );
\$cache_errored = is_array( \$raw_cache ) && ! empty( \$raw_cache['errored'] );
\$fetched = is_array( \$raw_cache ) && isset( \$raw_cache['fetched'] ) ? \$raw_cache['fetched'] : null;
\$data = is_array( \$raw_cache ) && isset( \$raw_cache['data'] ) && is_array( \$raw_cache['data'] )
	? \$raw_cache['data']
	: \$raw_cache;
\$currencies = is_array( \$data ) && isset( \$data['currencies'] ) && is_array( \$data['currencies'] )
	? \$data['currencies']
	: array();
\$updated = is_array( \$data ) && isset( \$data['updated'] ) ? \$data['updated'] : null;
\$requested = $currencies_php;
\$rates = array();
\$missing = array();
foreach ( \$requested as \$currency_code ) {
	\$currency_code = strtoupper( (string) \$currency_code );
	\$rate = \$currencies[ \$currency_code ] ?? \$currencies[ strtolower( \$currency_code ) ] ?? null;
	if ( ! is_numeric( \$rate ) || 0 >= (float) \$rate ) {
		\$missing[] = \$currency_code;
		continue;
	}
	\$rates[ \$currency_code ] = (string) \$rate;
}
WP_CLI::line( wp_json_encode( array(
	'schema' => 'woopayments_mc_rate_observation.v1',
	'role' => \$role,
	'runtime' => \$runtime,
	'provider' => \$provider_id,
	'provider_registered' => \$provider_registered,
	'provider_available' => \$provider_available,
	'fetched' => \$fetched,
	'updated' => \$updated,
	'observed_at' => time(),
	'rates' => \$rates,
	'missing' => \$missing,
	'cache_errored' => \$cache_errored,
) ) );
PHP
)"

	woopayments_mc_wp_eval_json "$wp_cmd" "$role rate inspection" "$inspect_php"
}

woopayments_mc_write_refresh_evidence() {
	local path="$1"
	local role="$2"
	local runtime="$3"
	local currencies_csv="$4"
	local configure_json="$5"
	local build_json="$6"
	local observation_json="$7"

	python3 - "$path" "$role" "$runtime" "$currencies_csv" "$configure_json" "$build_json" "$observation_json" <<'PY'
import json
import math
import sys
from pathlib import Path

path = Path(sys.argv[1])
role = sys.argv[2]
runtime = sys.argv[3]
requested = [currency for currency in sys.argv[4].split(",") if currency]
configure = json.loads(sys.argv[5])
build = json.loads(sys.argv[6])
observation = json.loads(sys.argv[7])

def number(value):
    if isinstance(value, bool):
        return None
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        return None
    return parsed if math.isfinite(parsed) else None

start = number(configure.get("refresh_started_at"))
fetched = number(observation.get("fetched"))
updated = number(observation.get("updated"))
observed = number(observation.get("observed_at"))
rates = observation.get("rates")
rates = rates if isinstance(rates, dict) else {}
valid_rates = True
for currency in requested:
    rate = number(rates.get(currency))
    if rate is None or rate <= 0:
        valid_rates = False
        break

checks = {
    "configuration_schema": configure.get("schema") == "woopayments_mc_automatic_configure.v1",
    "configuration_identity": configure.get("role") == role and configure.get("runtime") == runtime,
    "configured": configure.get("configured") is True,
    "currencies_match": configure.get("currencies_to") == requested,
    "automatic_rate_modes": configure.get("rate_modes") == {currency: "automatic" for currency in requested},
    "manual_rates_absent": configure.get("manual_rates_absent") is True,
    "shared_options_verified": configure.get("shared_options_verified") is True,
    "cache_absent_after_delete": configure.get("cache_absent_after_delete") is True,
    "build_schema": build.get("schema") == "woopayments_mc_runtime_build.v1",
    "build_identity": build.get("role") == role and build.get("runtime") == runtime,
    "state_built": build.get("state_built") is True,
    "provider_is_woopayments": build.get("provider") == "woopayments" and observation.get("provider") == "woopayments",
    "provider_registered": build.get("provider_registered") is True and observation.get("provider_registered") is True,
    "provider_available": build.get("provider_available") is True and observation.get("provider_available") is True,
    "observation_schema": observation.get("schema") == "woopayments_mc_rate_observation.v1",
    "observation_identity": observation.get("role") == role and observation.get("runtime") == runtime,
    "cache_not_errored": observation.get("cache_errored") is False,
    "requested_rates_present": observation.get("missing") == [] and valid_rates,
    "timestamps_are_numeric": all(value is not None for value in (start, fetched, updated, observed)),
    "cache_fetched_during_run": False,
    "cache_updated_during_run": False,
}
if all(value is not None for value in (start, fetched, updated, observed)):
    checks["cache_fetched_during_run"] = start - 2 <= fetched <= observed + 5
    checks["cache_updated_during_run"] = start - 2 <= updated <= observed + 5

details = []
for check, passed in checks.items():
    if not passed:
        details.append(
            {
                "code": f"{role}_{check}_mismatch",
                "classification": "environment",
                "scope": f"{role}_automatic_rate_refresh",
                "message": f"{role} automatic-rate proof failed: {check}",
            }
        )

payload = {
    "schema": "woopayments_mc_automatic_rate_evidence.v1",
    "status": "pass" if not details else "blocked",
    "role": role,
    "runtime": runtime,
    "configure": configure,
    "build": build,
    "observation": observation,
    "checks": checks,
    "failure_details": details,
}
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
print(payload["status"])
PY
}

woopayments_mc_restore_options() {
	local wp_cmd="$1"
	local role="$2"
	local snapshot_file="$3"
	local restore_php

	restore_php="$(cat <<PHP
/* woopayments_mc_state_restore */
\$role = '$role';
\$mismatches = array();
\$restored_count = 0;
\$cache_verified_count = 0;
\$expected_sha256 = '';
\$snapshot = array();
try {
	\$snapshot_envelope_json = file_get_contents( 'php://stdin' );
	if ( false === \$snapshot_envelope_json ) {
		throw new RuntimeException( 'snapshot_envelope_unavailable' );
	}
	\$snapshot_envelope = json_decode( \$snapshot_envelope_json, true, 512, JSON_THROW_ON_ERROR );
	if (
		! is_array( \$snapshot_envelope )
		|| 'woopayments_mc_option_snapshot.v1' !== ( \$snapshot_envelope['schema'] ?? '' )
		|| \$role !== ( \$snapshot_envelope['role'] ?? '' )
		|| ! isset( \$snapshot_envelope['snapshot_b64'], \$snapshot_envelope['snapshot_sha256'] )
	) {
		throw new RuntimeException( 'snapshot_envelope_invalid' );
	}
	\$snapshot_payload_json = base64_decode( (string) \$snapshot_envelope['snapshot_b64'], true );
	\$expected_sha256 = (string) \$snapshot_envelope['snapshot_sha256'];
	if ( false === \$snapshot_payload_json || hash( 'sha256', \$snapshot_payload_json ) !== \$expected_sha256 ) {
		throw new RuntimeException( 'snapshot_digest_mismatch' );
	}
	\$snapshot = json_decode( \$snapshot_payload_json, true, 512, JSON_THROW_ON_ERROR );
	if (
		! is_array( \$snapshot )
		|| 'woopayments_mc_option_snapshot_payload.v1' !== ( \$snapshot['schema'] ?? '' )
		|| ! isset( \$snapshot['option_names'], \$snapshot['options'] )
		|| ! is_array( \$snapshot['option_names'] )
		|| ! is_array( \$snapshot['options'] )
		|| count( \$snapshot['option_names'] ) !== count( \$snapshot['options'] )
	) {
		throw new RuntimeException( 'snapshot_payload_invalid' );
	}
	global \$wpdb;
	foreach ( \$snapshot['option_names'] as \$option_name ) {
		\$record = \$snapshot['options'][ \$option_name ] ?? null;
		if ( ! is_string( \$option_name ) || ! is_array( \$record ) || ! isset( \$record['exists'] ) ) {
			\$mismatches[] = (string) \$option_name . ':invalid_snapshot_record';
			continue;
		}
		if ( true === \$record['exists'] ) {
			\$option_value = isset( \$record['option_value_b64'] ) ? base64_decode( (string) \$record['option_value_b64'], true ) : false;
			\$autoload = isset( \$record['autoload'] ) ? (string) \$record['autoload'] : null;
			if ( false === \$option_value || null === \$autoload ) {
				\$mismatches[] = \$option_name . ':invalid_serialized_value';
				continue;
			}
			\$write_result = \$wpdb->query(
				\$wpdb->prepare(
					"INSERT INTO {\$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
					\$option_name,
					\$option_value,
					\$autoload
				)
			);
			if ( false === \$write_result ) {
				\$mismatches[] = \$option_name . ':write_failed';
				continue;
			}
		} else {
			\$delete_result = \$wpdb->delete( \$wpdb->options, array( 'option_name' => \$option_name ), array( '%s' ) );
			if ( false === \$delete_result ) {
				\$mismatches[] = \$option_name . ':delete_failed';
				continue;
			}
		}
		wp_cache_delete( \$option_name, 'options' );
		++\$restored_count;
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( \$snapshot['option_names'] as \$option_name ) {
		\$record = \$snapshot['options'][ \$option_name ] ?? array();
		\$wpdb->last_error = '';
		\$row = \$wpdb->get_row(
			\$wpdb->prepare(
				"SELECT option_value, autoload FROM {\$wpdb->options} WHERE option_name = %s LIMIT 1",
				\$option_name
			),
			ARRAY_A
		);
		if ( '' !== (string) \$wpdb->last_error ) {
			\$mismatches[] = \$option_name . ':verification_read_failed';
			continue;
		}
		\$expected_value = isset( \$record['option_value_b64'] ) ? base64_decode( (string) \$record['option_value_b64'], true ) : false;
		if ( false === ( \$record['exists'] ?? null ) ) {
			if ( null !== \$row ) {
				\$mismatches[] = \$option_name . ':expected_absent';
			}
		} elseif (
			null === \$row
			|| false === \$expected_value
			|| (string) \$row['option_value'] !== \$expected_value
			|| (string) \$row['autoload'] !== (string) ( \$record['autoload'] ?? '' )
		) {
			\$mismatches[] = \$option_name . ':value_mismatch';
		}

		wp_cache_delete( \$option_name, 'options' );
		\$cache_marker = new stdClass();
		\$cache_value = get_option( \$option_name, \$cache_marker );
		if ( false === ( \$record['exists'] ?? null ) ) {
			if ( \$cache_marker !== \$cache_value ) {
				\$mismatches[] = \$option_name . ':cache_expected_absent';
				continue;
			}
		} elseif (
			false === \$expected_value
			|| \$cache_marker === \$cache_value
			|| maybe_serialize( \$cache_value ) !== \$expected_value
		) {
			\$mismatches[] = \$option_name . ':cache_value_mismatch';
			continue;
		}
		++\$cache_verified_count;
	}
} catch ( Throwable \$error ) {
	\$mismatches[] = 'restore_exception:' . get_class( \$error ) . ':' . substr( \$error->getMessage(), 0, 120 );
}
\$mismatches = array_values( array_unique( \$mismatches ) );
WP_CLI::line( wp_json_encode( array(
	'schema' => 'woopayments_mc_option_restore.v1',
	'role' => \$role,
	'restored' => empty( \$mismatches ),
	'verified' => empty( \$mismatches ),
	'snapshot_sha256' => \$expected_sha256,
	'option_count' => isset( \$snapshot['option_names'] ) && is_array( \$snapshot['option_names'] ) ? count( \$snapshot['option_names'] ) : 0,
	'restored_count' => \$restored_count,
	'cache_verified_count' => \$cache_verified_count,
	'mismatches' => \$mismatches,
) ) );
PHP
)"

	woopayments_mc_wp_eval_json_stdin "$wp_cmd" "$role option restore" "$restore_php" "$snapshot_file"
}

woopayments_mc_validate_restore() {
	local restore_json="$1"
	local snapshot_file="$2"
	local role="$3"

	python3 - "$restore_json" "$snapshot_file" "$role" <<'PY'
import json
import sys
from pathlib import Path

restore = json.loads(sys.argv[1])
snapshot = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
role = sys.argv[3]
expected_count = snapshot.get("option_count")
valid = (
    restore.get("schema") == "woopayments_mc_option_restore.v1"
    and restore.get("role") == role
    and restore.get("restored") is True
    and restore.get("verified") is True
    and restore.get("snapshot_sha256") == snapshot.get("snapshot_sha256")
    and restore.get("option_count") == expected_count
    and restore.get("restored_count") == expected_count
    and restore.get("cache_verified_count") == expected_count
    and restore.get("mismatches") == []
)
if not valid:
    raise SystemExit(1)
PY
}
