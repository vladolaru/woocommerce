#!/usr/bin/env bash
# Shared helpers for the critical-flows parity suite.
# Store selection + WP-CLI wrappers + state assertions. Source this from flows/*.sh.
#
# Stores (dual-store oracle):
#   reference (:8082) = current WC + WooPayments extension (golden)
#   target    (:8889) = native WooPayments-in-core (this checkout)
#
# NOTE: the exact WP-CLI invocation per store depends on the local env wiring.
# Defaults below match the documented topology (target via wp-env, reference via its
# docker-compose container). Override with env vars if your setup differs.

set -uo pipefail

COMMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$COMMON_DIR/../../.." && pwd)}"
WC_DIR="${WC_DIR:-$REPO_ROOT/plugins/woocommerce}"
EVIDENCE_DIR="${EVIDENCE_DIR:-$REPO_ROOT/tools/woopayments-critical-flows/evidence}"

# Reference store container (current WooPayments extension), :8082.
REF_CONTAINER="${REF_CONTAINER:-wcpay_wp_default}"
# Target store: wp-env wrapper from the WC dir, :8889.
TARGET_WPENV_CWD="${TARGET_WPENV_CWD:-wp-content/plugins/woocommerce}"

# wp_target <wp-cli args...> : run WP-CLI against the native target store.
wp_target() { ( cd "$WC_DIR" && pnpm wp-env run --env-cwd="$TARGET_WPENV_CWD" cli wp "$@" ); }
# wp_ref <wp-cli args...> : run WP-CLI against the reference store.
wp_ref() { docker exec -u www-data "$REF_CONTAINER" wp "$@"; }

# wp_store <ref|target> <args...> : dispatch by store name.
wp_store() { local s="$1"; shift; case "$s" in ref) wp_ref "$@";; target) wp_target "$@";; *) echo "unknown store: $s" >&2; return 2;; esac; }

# ---- assertions (Layer D) -------------------------------------------------
# Each prints PASS/FAIL and returns 0/1; capture for the verdict rollup.

assert_order_status() { # <store> <order_id> <expected_status>
  local s="$1" id="$2" want="$3" got
  got="$(wp_store "$s" wc shop_order get "$id" --field=status 2>/dev/null)"
  [ "$got" = "$want" ] && { echo "PASS order #$id status=$got"; return 0; }
  echo "FAIL order #$id status=$got want=$want"; return 1
}

assert_meta_present() { # <store> <order_id> <meta_key>  (Bucket-E key must exist + non-empty)
  local s="$1" id="$2" key="$3" val
  val="$(wp_store "$s" post meta get "$id" "$key" 2>/dev/null)"
  [ -n "$val" ] && { echo "PASS order #$id $key=$val"; return 0; }
  echo "FAIL order #$id $key missing/empty"; return 1
}

assert_log_clean() { # <store>  (no PHP notice/warning/fatal/deprecation since marker)
  local s
  s="$1"
  echo "BLOCKED log-clean check for $s: debug.log scan is not wired for this local store"
  return 3
}

# ref_vs_target_diff <metric-name> <ref-value> <target-value>
# Parity helper: equal => PASS, else FAIL with both values for the verdict note.
ref_vs_target_diff() { # <name> <ref> <target>
  [ "$2" = "$3" ] && { echo "PASS $1 ref==target ($2)"; return 0; }
  echo "FAIL $1 ref=$2 target=$3"; return 1
}

mkdir -p "$EVIDENCE_DIR"
