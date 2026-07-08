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
REF_WP_COMMAND="${REF_WP_COMMAND:-}"
# Target store: wp-env wrapper from the WC dir, :8889.
TARGET_WPENV_CWD="${TARGET_WPENV_CWD:-wp-content/plugins/woocommerce}"
TARGET_WP_COMMAND="${TARGET_WP_COMMAND:-}"

run_wp_command_string() {
  local command="$1"
  shift

  # Intentionally split the configured local WP runner string, matching the
  # verification harness's WP="docker exec ..." convention.
  # shellcheck disable=SC2086
  $command "$@"
}

# wp_target <wp-cli args...> : run WP-CLI against the native target store.
wp_target() {
  if [ -n "$TARGET_WP_COMMAND" ]; then
    run_wp_command_string "$TARGET_WP_COMMAND" "$@"
    return $?
  fi
  ( cd "$WC_DIR" && pnpm wp-env run --env-cwd="$TARGET_WPENV_CWD" cli wp "$@" )
}
# wp_ref <wp-cli args...> : run WP-CLI against the reference store.
wp_ref() {
  if [ -n "$REF_WP_COMMAND" ]; then
    run_wp_command_string "$REF_WP_COMMAND" "$@"
    return $?
  fi
  docker exec -u www-data "$REF_CONTAINER" wp "$@"
}

# wp_store <ref|target> <args...> : dispatch by store name.
wp_store() { local s="$1"; shift; case "$s" in ref) wp_ref "$@";; target) wp_target "$@";; *) echo "unknown store: $s" >&2; return 2;; esac; }

# ---- assertions (Layer D) -------------------------------------------------
# Each prints PASS/FAIL and returns 0/1; capture for the verdict rollup.

wp_php_literal() { # <value>
  php -r 'echo var_export($argv[1], true);' "$1"
}

mark_log_clean_start() { # <store>
  local s raw rc
  s="$1"
  raw="$(wp_store "$s" eval '
$paths = array();
if ( defined( "WP_DEBUG_LOG" ) && is_string( WP_DEBUG_LOG ) && "" !== WP_DEBUG_LOG && "1" !== WP_DEBUG_LOG ) {
	$paths[] = WP_DEBUG_LOG;
}
if ( defined( "WP_CONTENT_DIR" ) ) {
	$paths[] = WP_CONTENT_DIR . "/debug.log";
}
$paths   = array_values( array_unique( array_filter( $paths ) ) );
$markers = array();

foreach ( $paths as $path ) {
	if ( ! is_string( $path ) || "" === $path || ! is_readable( $path ) ) {
		continue;
	}

	$lines = @file( $path, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		continue;
	}

	$markers[ $path ] = count( $lines );
}

update_option(
	"woopayments_critical_flows_debug_log_marker",
	array(
		"created_at" => gmdate( "c" ),
		"paths"      => $markers,
	),
	false
);

WP_CLI::line(
	wp_json_encode(
		array(
			"status"  => "pass",
			"paths"   => $paths,
			"markers" => $markers,
		)
	)
);
' 2>&1)"
  rc=$?

  if [ "$rc" -ne 0 ]; then
    echo "BLOCKED log-clean marker for $s: debug.log marker command failed"
    printf '%s\n' "$raw" | tail -20
    return 3
  fi

  echo "[$s] log-clean marker recorded"
  return 0
}

assert_order_status() { # <store> <order_id> <expected_status>
  local s="$1" id="$2" want="$3" got
  got="$(wp_store "$s" wc shop_order get "$id" --field=status 2>/dev/null)"
  [ "$got" = "$want" ] && { echo "PASS order #$id status=$got"; return 0; }
  echo "FAIL order #$id status=$got want=$want"; return 1
}

assert_order_meta_present() { # <store> <order_id> <meta_key>  (Bucket-E key must exist + non-empty)
  local s="$1" id="$2" key="$3" key_literal script val

  case "$id" in
    ''|*[!0-9]*)
      echo "FAIL order #$id $key missing/empty"
      return 1
      ;;
  esac

  key_literal="$(wp_php_literal "$key")"
  script="$(cat <<PHP
\$order = wc_get_order( $id );
if ( \$order ) {
	\$value = \$order->get_meta( $key_literal, true );
	if ( is_array( \$value ) || is_object( \$value ) ) {
		\$value = wp_json_encode( \$value );
	}
	if ( null !== \$value && "" !== (string) \$value ) {
		WP_CLI::line( (string) \$value );
	}
}
PHP
)"
  val="$(wp_store "$s" eval "$script" 2>/dev/null)"
  [ -n "$val" ] && { echo "PASS order #$id $key=$val"; return 0; }
  echo "FAIL order #$id $key missing/empty"; return 1
}

assert_log_clean() { # <store>  (no PHP notice/warning/fatal/deprecation since marker)
  local s raw rc
  s="$1"
  raw="$(wp_store "$s" eval '
$paths = array();
if ( defined( "WP_DEBUG_LOG" ) && is_string( WP_DEBUG_LOG ) && "" !== WP_DEBUG_LOG && "1" !== WP_DEBUG_LOG ) {
	$paths[] = WP_DEBUG_LOG;
}
if ( defined( "WP_CONTENT_DIR" ) ) {
	$paths[] = WP_CONTENT_DIR . "/debug.log";
}
$paths    = array_values( array_unique( array_filter( $paths ) ) );
$readable = array();
$matches  = array();
$ignored_matches = array();
$marker = get_option( "woopayments_critical_flows_debug_log_marker", array() );
$marker_paths = array();
if ( is_array( $marker ) && isset( $marker["paths"] ) && is_array( $marker["paths"] ) ) {
	foreach ( $marker["paths"] as $marker_path => $line_count ) {
		$marker_paths[ (string) $marker_path ] = max( 0, (int) $line_count );
	}
}

foreach ( $paths as $path ) {
	if ( ! is_string( $path ) || "" === $path || ! is_readable( $path ) ) {
		continue;
	}

	$readable[] = $path;
	$lines      = @file( $path, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		continue;
	}

	$marker_line = isset( $marker_paths[ $path ] ) ? $marker_paths[ $path ] : 0;
	foreach ( $lines as $line_number => $line ) {
		if ( ( $line_number + 1 ) <= $marker_line ) {
			continue;
		}

		if ( preg_match( "/Function _load_textdomain_just_in_time was called/i", $line ) ) {
			$ignored_matches[] = basename( $path ) . ":" . ( $line_number + 1 ) . ": " . trim( $line );
			continue;
		}

		if ( preg_match( "/\\b(PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards)\\b/i", $line ) ) {
			$matches[] = basename( $path ) . ":" . ( $line_number + 1 ) . ": " . trim( $line );
			if ( count( $matches ) >= 20 ) {
				break 2;
			}
		}
	}
}

if ( empty( $readable ) ) {
	WP_CLI::line(
		wp_json_encode(
			array(
				"status" => "blocked",
				"reason" => "no readable debug.log path",
				"paths"  => $paths,
				"ignored_matches" => $ignored_matches,
				"marker" => $marker,
			)
		)
	);
	return;
}

WP_CLI::line(
	wp_json_encode(
		array(
			"status"  => empty( $matches ) ? "pass" : "fail",
			"paths"   => $readable,
			"matches" => $matches,
			"ignored_matches" => $ignored_matches,
			"marker" => $marker,
		)
	)
);
' 2>&1)"
  rc=$?

  if [ "$rc" -ne 0 ]; then
    echo "BLOCKED log-clean check for $s: debug.log scan command failed"
    printf '%s\n' "$raw" | tail -20
    return 3
  fi

  LOG_SCAN_RAW="$raw" python3 - "$s" <<'PY'
import json
import os
import sys

store = sys.argv[1]
raw = os.environ.get("LOG_SCAN_RAW", "")
decoder = json.JSONDecoder()
payload = None
for index, char in enumerate(raw):
    if char != "{":
        continue
    try:
        candidate, _ = decoder.raw_decode(raw[index:])
    except json.JSONDecodeError:
        continue
    if isinstance(candidate, dict) and "status" in candidate:
        payload = candidate
        break

if payload is None:
    print(f"BLOCKED log-clean check for {store}: debug.log scan emitted no JSON")
    if raw.strip():
        print(raw.strip())
    sys.exit(3)

status = payload.get("status")
paths = payload.get("paths") or []
matches = payload.get("matches") or []
path_note = ", ".join(paths) if paths else "no paths"

if status == "pass":
    print(f"PASS log-clean {store}: scanned {path_note}")
    sys.exit(0)

if status == "fail":
    print(f"FAIL log-clean {store}: PHP log entries found in {path_note}")
    for match in matches[:10]:
        print(f"  {match}")
    sys.exit(1)

reason = payload.get("reason") or "debug.log scan did not pass"
print(f"BLOCKED log-clean check for {store}: {reason}; paths={path_note}")
sys.exit(3)
PY
}

# ref_vs_target_diff <metric-name> <ref-value> <target-value>
# Parity helper: equal => PASS, else FAIL with both values for the verdict note.
ref_vs_target_diff() { # <name> <ref> <target>
  [ "$2" = "$3" ] && { echo "PASS $1 ref==target ($2)"; return 0; }
  echo "FAIL $1 ref=$2 target=$3"; return 1
}

mkdir -p "$EVIDENCE_DIR"
