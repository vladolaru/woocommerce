#!/usr/bin/env bash
# Idempotent fixture setup for the critical-flows suite. Run identically on both stores.
# Snapshot+restore the account cache so eligibility-flag flips don't leak between runs.
#
# Usage: source ../lib/common.sh; then call the fns below with a store name (ref|target).

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

# --- products -------------------------------------------------------------
ensure_product() { # <store> <sku> <create args...>
  local s="$1" sku="$2" existing
  shift 2

  if ! existing="$(wp_store "$s" --user=1 wc product list "--sku=$sku" --field=id 2>/dev/null | head -1)"; then
    echo "[$s] FAIL product lookup failed: $sku"
    return 1
  fi

  if [ -n "$existing" ]; then
    echo "[$s] product exists: $sku (#$existing)"
    return 0
  fi

  if ! wp_store "$s" --user=1 wc product create "$@" >/dev/null; then
    echo "[$s] FAIL product create failed: $sku"
    return 1
  fi

  echo "[$s] product created: $sku"
}

php_literal() { # <value>
  php -r 'echo var_export($argv[1], true);' "$1"
}

ensure_subscription_product() { # <store> <sku> <name> <price> <trial_length> <trial_period>
  local s="$1" sku="$2" name="$3" price="$4" trial_length="$5" trial_period="$6" existing script

  if ! existing="$(wp_store "$s" --user=1 wc product list "--sku=$sku" --field=id 2>/dev/null | head -1)"; then
    echo "[$s] FAIL product lookup failed: $sku"
    return 1
  fi

  if [ -n "$existing" ]; then
    echo "[$s] product exists: $sku (#$existing)"
    return 0
  fi

  script="$(cat <<PHP
if ( ! class_exists( 'WC_Product_Subscription' ) ) {
	WP_CLI::error( 'WC_Product_Subscription is unavailable.' );
}

\$sku          = $(php_literal "$sku");
\$name         = $(php_literal "$name");
\$price        = $(php_literal "$price");
\$trial_length = $(php_literal "$trial_length");
\$trial_period = $(php_literal "$trial_period");

\$product = new WC_Product_Subscription();
\$product->set_name( \$name );
\$product->set_sku( \$sku );
\$product->set_status( 'publish' );
\$product->set_catalog_visibility( 'visible' );
\$product->set_virtual( true );
\$product->set_regular_price( \$price );
\$product->set_price( \$price );
\$product_id = \$product->save();

update_post_meta( \$product_id, '_subscription_price', \$price );
update_post_meta( \$product_id, '_subscription_period', 'month' );
update_post_meta( \$product_id, '_subscription_period_interval', '1' );
update_post_meta( \$product_id, '_subscription_sign_up_fee', '0' );
update_post_meta( \$product_id, '_subscription_trial_length', \$trial_length );
update_post_meta( \$product_id, '_subscription_trial_period', \$trial_period );

WP_CLI::line( wp_json_encode( array( 'id' => \$product_id, 'sku' => \$sku ) ) );
PHP
)"

  if ! wp_store "$s" --user=1 eval "$script" >/dev/null; then
    echo "[$s] FAIL subscription product create failed: $sku"
    return 1
  fi

  echo "[$s] product created: $sku"
}

fixture_products() { # <store>
  local s="$1"
  echo "[$s] ensure products: simple-\$20 (SKU cf-simple), simple-\$50 (cf-affirm), variable (cf-var), subscription (cf-sub), free-trial-sub (cf-trial)"
  ensure_product "$s" cf-simple --name="CF Simple" --sku=cf-simple --type=simple --regular_price=20 --status=publish || return 1
  ensure_product "$s" cf-affirm --name="CF Affirm" --sku=cf-affirm --type=simple --regular_price=50 --status=publish || return 1
  ensure_product "$s" cf-var --name="CF Variable" --sku=cf-var --type=variable --status=publish || return 1
  ensure_subscription_product "$s" cf-sub "CF Subscription" 20 0 day || return 1
  ensure_subscription_product "$s" cf-trial "CF Trial Subscription" 20 14 day || return 1
}

# --- settings toggles -----------------------------------------------------
fixture_settings() { # <store> <key=value...>  e.g. capture=automatic saved_cards=yes woopay=no
  local s="$1" raw updated
  shift
  echo "[$s] set WooPayments settings: $*"
  raw="$(wp_store "$s" option get woocommerce_woocommerce_payments_settings --format=json 2>/dev/null || printf '{}')"
  if ! updated="$(python3 - "$raw" "$@" <<'PY'
import json
import sys

try:
    settings = json.loads(sys.argv[1] or "{}")
except json.JSONDecodeError:
    settings = {}
if not isinstance(settings, dict):
    settings = {}

def yes_no(value: str) -> str:
    normalized = value.strip().lower()
    if normalized in {"1", "true", "yes", "on", "enabled"}:
        return "yes"
    if normalized in {"0", "false", "no", "off", "disabled"}:
        return "no"
    raise SystemExit(f"invalid boolean setting value: {value}")

for arg in sys.argv[2:]:
    if "=" not in arg:
        raise SystemExit(f"invalid setting argument: {arg}")
    key, value = arg.split("=", 1)
    key = key.strip()
    value = value.strip()
    if key == "capture":
        if value == "automatic":
            settings["manual_capture"] = "no"
        elif value == "manual":
            settings["manual_capture"] = "yes"
        else:
            raise SystemExit(f"invalid capture mode: {value}")
    elif key == "saved_cards":
        settings["saved_cards"] = yes_no(value)
    elif key == "woopay":
        settings["platform_checkout"] = yes_no(value)
    else:
        raise SystemExit(f"unknown WooPayments fixture setting: {key}")

settings["enabled"] = "yes"
settings["test_mode"] = "yes"
methods = settings.get("upe_enabled_payment_method_ids")
if not isinstance(methods, list):
    methods = []
if "card" not in methods:
    methods.append("card")
settings["upe_enabled_payment_method_ids"] = methods

print(json.dumps(settings, sort_keys=True, separators=(",", ":")))
PY
)"; then
    echo "[$s] FAIL could not build WooPayments settings payload"
    return 1
  fi
  if ! wp_store "$s" option update woocommerce_woocommerce_payments_settings "$updated" --format=json >/dev/null; then
    echo "[$s] FAIL WooPayments settings update failed"
    return 1
  fi
  echo "[$s] WooPayments settings updated"
}

# --- account eligibility flags (via WCPay Dev Tools cache) -----------------
account_snapshot() { # <store> -> saves cache to evidence for restore
  local s="$1"
  wp_store "$s" option get wcpay_account_data --format=json > "$EVIDENCE_DIR/${s}-account-cache.snapshot.json" 2>/dev/null \
    && echo "[$s] account cache snapshotted" || echo "[$s] WARN no account cache to snapshot"
}
account_restore() { # <store>
  local s="$1" f="$EVIDENCE_DIR/${s}-account-cache.snapshot.json"
  [ -s "$f" ] && wp_store "$s" option update wcpay_account_data "$(cat "$f")" --format=json \
    && echo "[$s] account cache restored" || echo "[$s] WARN nothing to restore"
}
account_set_flags() { # <store> <flag=bool...>  e.g. is_documents_enabled=true has_card_readers_available=true
  local s="$1" raw updated
  shift
  echo "[$s] set eligibility flags: $* (snapshot first, restore in finally)"
  raw="$(wp_store "$s" option get wcpay_account_data --format=json 2>/dev/null || printf '{}')"
  if ! updated="$(python3 - "$raw" "$@" <<'PY'
import json
import sys

try:
    account = json.loads(sys.argv[1] or "{}")
except json.JSONDecodeError:
    account = {}
if not isinstance(account, dict):
    account = {}

def parse_bool(value: str) -> bool:
    normalized = value.strip().lower()
    if normalized in {"1", "true", "yes", "on"}:
        return True
    if normalized in {"0", "false", "no", "off"}:
        return False
    raise SystemExit(f"invalid boolean flag value: {value}")

for arg in sys.argv[2:]:
    if "=" not in arg:
        raise SystemExit(f"invalid flag argument: {arg}")
    key, value = arg.split("=", 1)
    account[key.strip()] = parse_bool(value)

print(json.dumps(account, sort_keys=True, separators=(",", ":")))
PY
)"; then
    echo "[$s] FAIL could not build eligibility flag payload"
    return 1
  fi
  if ! wp_store "$s" option update wcpay_account_data "$updated" --format=json >/dev/null; then
    echo "[$s] FAIL eligibility flag update failed"
    return 1
  fi
  echo "[$s] eligibility flags updated"
}

# --- coupons + shipping ---------------------------------------------------
ensure_coupon() { # <store> <code> <create args...>
  local s="$1" code="$2" existing
  shift 2

  if ! existing="$(wp_store "$s" --user=1 wc shop_coupon list "--code=$code" --field=id 2>/dev/null | head -1)"; then
    echo "[$s] FAIL coupon lookup failed: $code"
    return 1
  fi

  if [ -n "$existing" ]; then
    echo "[$s] coupon exists: $code (#$existing)"
    return 0
  fi

  if ! wp_store "$s" --user=1 wc shop_coupon create "--code=$code" "$@" >/dev/null; then
    echo "[$s] FAIL coupon create failed: $code"
    return 1
  fi

  echo "[$s] coupon created: $code"
}

fixture_coupons() { # <store>
  local s="$1"
  echo "[$s] ensure coupons: cf-signup cf-oneoff cf-recurring"
  ensure_coupon "$s" cf-signup --amount=5 --discount_type=sign_up_fee --description="Critical flow signup-fee discount" || return 1
  ensure_coupon "$s" cf-oneoff --amount=5 --discount_type=fixed_cart --description="Critical flow one-off discount" || return 1
  ensure_coupon "$s" cf-recurring --amount=5 --discount_type=recurring_fee --description="Critical flow recurring discount" || return 1
}

fixture_shipping() { # <store>
  local s="$1" script
  echo "[$s] ensure zone w/ flat-rate \$20 + free-shipping"
  script="$(cat <<'PHP'
$zone = new WC_Shipping_Zone( 0 );

$ensure_method = static function ( WC_Shipping_Zone $zone, string $method_id, array $settings, int $order ): int {
	$instance_id = 0;
	foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
		if ( isset( $method->id ) && $method_id === $method->id ) {
			$instance_id = absint( $method->instance_id );
			break;
		}
	}

	if ( ! $instance_id ) {
		$instance_id = absint( $zone->add_shipping_method( $method_id ) );
		if ( ! $instance_id ) {
			WP_CLI::error( "Could not create {$method_id} shipping method." );
		}
	}

	$method = WC_Shipping_Zones::get_shipping_method( $instance_id );
	if ( ! $method ) {
		WP_CLI::error( "Could not load {$method_id} shipping method instance {$instance_id}." );
	}

	$method->init_instance_settings();
	$instance_settings = array_merge( $method->instance_settings, $settings );
	update_option(
		$method->get_instance_option_key(),
		apply_filters( 'woocommerce_shipping_' . $method->id . '_instance_settings_values', $instance_settings, $method ),
		'yes'
	);

	global $wpdb;
	$wpdb->update(
		$wpdb->prefix . 'woocommerce_shipping_zone_methods',
		array(
			'is_enabled'   => 1,
			'method_order' => $order,
		),
		array( 'instance_id' => $instance_id ),
		array( '%d', '%d' ),
		array( '%d' )
	);

	return $instance_id;
};

$flat_rate_id = $ensure_method(
	$zone,
	'flat_rate',
	array(
		'title' => 'Flat rate shipping',
		'cost'  => '20',
	),
	1
);
$free_shipping_id = $ensure_method(
	$zone,
	'free_shipping',
	array(
		'title'    => 'Free shipping',
		'requires' => '',
	),
	2
);
WC_Cache_Helper::get_transient_version( 'shipping', true );
WP_CLI::line( wp_json_encode( array( 'flat_rate' => $flat_rate_id, 'free_shipping' => $free_shipping_id ) ) );
PHP
)"
  if ! wp_store "$s" --user=1 eval "$script" >/dev/null; then
    echo "[$s] FAIL shipping fixture setup failed"
    return 1
  fi
  echo "[$s] shipping fixture updated"
}

# Full setup for a store.
fixture_all() { # <store>
  local s="$1"
  fixture_products "$s" || return 1
  fixture_settings "$s" capture=automatic saved_cards=yes || return 1
  fixture_coupons "$s" || return 1
  fixture_shipping "$s" || return 1
  echo "[$s] base fixtures ready"
}

"$@"  # allow: ./fixtures.sh fixture_all target
