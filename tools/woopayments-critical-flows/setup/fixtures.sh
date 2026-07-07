#!/usr/bin/env bash
# Idempotent fixture setup for the critical-flows suite. Run identically on both stores.
# Snapshot+restore the account cache so eligibility-flag flips don't leak between runs.
#
# Usage: source ../lib/common.sh; then call the fns below with a store name (ref|target).

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

# --- products -------------------------------------------------------------
fixture_products() { # <store>
  local s="$1"
  # Idempotent: create only if absent (match by SKU). TODO wire real wc product create.
  echo "[$s] ensure products: simple-\$20 (SKU cf-simple), simple-\$50 (cf-affirm), variable (cf-var), subscription (cf-sub), free-trial-sub (cf-trial)"
  # wp_store "$s" wc product create --name="CF Simple" --sku=cf-simple --regular_price=20 ...
}

# --- settings toggles -----------------------------------------------------
fixture_settings() { # <store> <key=value...>  e.g. capture=automatic saved_cards=yes woopay=no
  local s="$1"; shift
  echo "[$s] set WooPayments settings: $*"
  # Map each to the gateway option / REST settings endpoint. TODO wire.
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
  local s="$1"; shift
  echo "[$s] set eligibility flags: $* (snapshot first, restore in finally)"
  # Read cache JSON, patch flags, write back. TODO wire jq patch.
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
