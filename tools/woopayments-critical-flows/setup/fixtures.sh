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

fixture_products() { # <store>
  local s="$1"
  echo "[$s] ensure products: simple-\$20 (SKU cf-simple), simple-\$50 (cf-affirm), variable (cf-var), subscription (cf-sub), free-trial-sub (cf-trial)"
  ensure_product "$s" cf-simple --name="CF Simple" --sku=cf-simple --type=simple --regular_price=20 --status=publish
  ensure_product "$s" cf-affirm --name="CF Affirm" --sku=cf-affirm --type=simple --regular_price=50 --status=publish
  ensure_product "$s" cf-var --name="CF Variable" --sku=cf-var --type=variable --status=publish
  ensure_product "$s" cf-sub --name="CF Subscription" --sku=cf-sub --type=subscription --regular_price=20 --subscription_period=month --subscription_period_interval=1 --status=publish
  ensure_product "$s" cf-trial --name="CF Trial Subscription" --sku=cf-trial --type=subscription --regular_price=20 --subscription_period=month --subscription_period_interval=1 --subscription_trial_length=14 --subscription_trial_period=day --status=publish
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
fixture_coupons() { local s="$1"; echo "[$s] ensure coupons: cf-signup cf-oneoff cf-recurring"; }
fixture_shipping() { local s="$1"; echo "[$s] ensure zone w/ flat-rate \$20 + free-shipping"; }

# Full setup for a store.
fixture_all() { # <store>
  local s="$1"
  fixture_products "$s"; fixture_settings "$s" capture=automatic saved_cards=yes
  fixture_coupons "$s"; fixture_shipping "$s"
  echo "[$s] base fixtures ready"
}

"$@"  # allow: ./fixtures.sh fixture_all target
