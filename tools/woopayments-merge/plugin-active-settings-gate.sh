#!/usr/bin/env bash
#
# Plugin-active WooPayments settings gate for the WooPayments -> core merge harness.
#
# This local-only transition harness verifies that the target store still renders
# the standalone WooPayments settings screen while the plugin owns the payments
# surface. It fails closed before opening the browser if the plugin is not active.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	printf 'FAIL: local runner safety library is missing: %s\n' "$LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"
BROWSER_DRIVER="$SELF_DIR/plugin-active-settings.playwriter.mjs"

TARGET_WP=""
TARGET_URL="${TARGET_URL:-}"
RUNNER_ROLE="${RUNNER_ROLE:-target}"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
BROWSER_RUNNER="${BROWSER_RUNNER:-playwriter}"
PLAYWRIGHT_SCRIPT_RUNNER_BIN="${PLAYWRIGHT_SCRIPT_RUNNER_BIN:-$SELF_DIR/playwright-script-runner.mjs}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-password}"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/plugin-active-settings-gate"
PRINT_PLAN=0
PREFLIGHT_ONLY=0
STAGE_PLUGIN_ACTIVE_FIXTURE=0
CLEANUP_ARMED=0
SNAPSHOT_FIXTURE_JSON=""
STAGE_FIXTURE_JSON=""
RESTORE_FIXTURE_JSON=""
CONTEXT_FILE=""
CONTEXT_SHA256=""

usage() {
	cat >&2 <<'USAGE'
usage:
  plugin-active-settings-gate.sh --target "<target wp>" --target-url <url> [options]

Options:
  --target "<wp>"              Target store WP-CLI command.
  --target-url <url>           Target store browser base URL.
  --runner-role <role>         Approved aggregate runner role: reference or target. Default: target.
  --browser-runner <runner>    Browser runner: playwriter or playwright. Defaults to BROWSER_RUNNER or playwriter.
  --playwriter-session <id>    Existing Playwriter session id. Defaults to PLAYWRITER_SESSION.
  --out-dir <path>             Evidence output directory.
  --context-file <path>        Aggregate critical-flow context to bind after cleanup.
  --stage-plugin-active-fixture
                                Temporarily disable native mu-plugin toggles,
                                activate WooPayments, and restore afterward.
  --preflight-only             Validate arguments, dependencies, and plugin-active state, then exit.
  --print-plan                 Print the normalized gate plan as JSON, then exit.
  -h, --help                   Show this help.

The gate requires the WooPayments plugin to be active on the target store. It
then opens the real wp-admin WooPayments settings URL and rejects blank screens,
login redirects, hard failed responses, and duplicate wc/payments/settings store
registration errors. Known local optional-response preconditions are reported
as blocked evidence, not product failures.
USAGE
}

progress() {
	printf 'Plugin-active settings gate: %s\n' "$*" >&2
}

blocked() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 3
}

usage_error() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--target-url=*) TARGET_URL="${1#--target-url=}"; shift ;;
		--target-url) TARGET_URL="${2:-}"; shift 2 ;;
		--runner-role=*) RUNNER_ROLE="${1#--runner-role=}"; shift ;;
		--runner-role) RUNNER_ROLE="${2:-}"; shift 2 ;;
		--browser-runner=*) BROWSER_RUNNER="${1#--browser-runner=}"; shift ;;
		--browser-runner) BROWSER_RUNNER="${2:-}"; shift 2 ;;
		--playwriter-session=*) PLAYWRITER_SESSION="${1#--playwriter-session=}"; shift ;;
		--playwriter-session) PLAYWRITER_SESSION="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--context-file=*) CONTEXT_FILE="${1#--context-file=}"; shift ;;
		--context-file) CONTEXT_FILE="${2:-}"; shift 2 ;;
		--stage-plugin-active-fixture) STAGE_PLUGIN_ACTIVE_FIXTURE=1; shift ;;
		--preflight-only) PREFLIGHT_ONLY=1; shift ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

if [ -z "$TARGET_WP" ] || [ -z "$TARGET_URL" ]; then
	usage_error "--target and --target-url are required."
fi
if [ -n "$CONTEXT_FILE" ] && [ ! -f "$CONTEXT_FILE" ]; then
	usage_error "--context-file does not exist: $CONTEXT_FILE"
fi
if [ -n "$CONTEXT_FILE" ]; then
	if ! CONTEXT_SHA256="$(python3 - "$CONTEXT_FILE" <<'PY'
import json
import re
import sys
from pathlib import Path

try:
    payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
except (OSError, UnicodeError, json.JSONDecodeError) as exc:
    raise SystemExit(f"could not load aggregate context: {exc}")
digest = payload.get("context_sha256")
if not isinstance(digest, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", digest):
    raise SystemExit("aggregate context has an invalid context_sha256")
print(digest)
PY
)"; then
		usage_error "--context-file is invalid."
	fi
fi
if ! runner_error="$(woopayments_validate_local_wp_runner "$TARGET_WP")"; then
	usage_error "unsafe target WP runner: $runner_error"
fi
case "$RUNNER_ROLE" in
	reference|target) ;;
	*) usage_error "--runner-role must be reference or target." ;;
esac
if ! runner_error="$(woopayments_validate_approved_docker_runner "$TARGET_WP" "$RUNNER_ROLE")"; then
	usage_error "unapproved $RUNNER_ROLE WP runner: $runner_error"
fi

case "$BROWSER_RUNNER" in
	playwriter|playwright) ;;
	*) usage_error "unsupported browser runner: $BROWSER_RUNNER" ;;
esac

TARGET_URL="${TARGET_URL%/}"
SETTINGS_URL="$TARGET_URL/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments"

validate_local_url() {
	local label="$1"
	local value="$2"

	python3 - "$label" "$value" <<'PY'
import sys
from urllib.parse import urlparse

label, value = sys.argv[1:]
parsed = urlparse(value)
host = parsed.hostname or ""
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(f"{label} must be http(s), got {value}")
if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
    raise SystemExit(f"{label} must stay local, got {value}")
PY
}

print_plan() {
	python3 - "$TARGET_WP" "$TARGET_URL" "$SETTINGS_URL" "$BROWSER_DRIVER" "$RUNNER_ROLE" <<'PY'
import json
import sys

target_wp, target_url, settings_url, browser_driver, runner_role = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_plugin_active_settings_gate_plan.v1",
            "target_wp": target_wp,
            "target_url": target_url,
            "settings_url": settings_url,
            "browser_driver": browser_driver,
            "runner_role": runner_role,
            "checks": [
                "woocommerce-payments plugin is active before browser run",
                "authenticated wp-admin settings page renders",
                "WooPayments settings screen is present",
                "standalone WooPayments settings script and localized global are present",
                "no duplicate wc/payments/settings store registration error",
            ],
        },
        sort_keys=True,
    )
)
PY
}

assert_plugin_active() {
	local output

	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	if ! output="$($TARGET_WP plugin is-active woocommerce-payments 2>&1)"; then
		blocked "WooPayments plugin is not active on the target store; run this gate before native cutover or restore the plugin-active fixture. $output"
	fi
}

record_failure() {
	printf '%s\n' "$*" >> "$FAILURES_FILE"
}

record_blocker() {
	printf '%s\n' "$*" >> "$BLOCKERS_FILE"
}

json_success() {
	python3 - "$1" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    payload = json.load(stream)
raise SystemExit(0 if isinstance(payload, dict) and payload.get("success") else 1)
PY
}

json_errors() {
	python3 - "$1" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    raise SystemExit(0)
for error in payload.get("errors", []):
    print(f"  - {error}", file=sys.stderr)
PY
}

bind_snapshot_digest() {
	python3 - "$1" "$SNAPSHOT_FIXTURE_JSON" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

artifact_path, snapshot_path = (Path(value) for value in sys.argv[1:])
try:
    payload = json.loads(artifact_path.read_text(encoding="utf-8"))
except (OSError, UnicodeError, json.JSONDecodeError) as exc:
    raise SystemExit(f"could not bind fixture snapshot digest: {exc}")
payload["snapshot_sha256"] = "sha256:" + hashlib.sha256(snapshot_path.read_bytes()).hexdigest()
artifact_path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

probe_runtime_owner() {
	local raw rc json

	# A fresh WP request is required here. In the restore request, the deactivated
	# plugin's classes remain loaded until process exit and would make the arbiter's
	# non-standard-install fallback report a stale plugin owner.
	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval-file - probe-runtime-owner <<'PHP' 2>&1
<?php
$errors        = array();
$runtime_owner = 'unknown';
$arbiter_class = '\\Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter';

if ( ! class_exists( $arbiter_class ) || ! function_exists( 'wc_get_container' ) ) {
	$errors[] = 'Native payments runtime arbiter is unavailable.';
} else {
	try {
		$runtime_owner = (string) wc_get_container()->get( $arbiter_class )->get_runtime_owner();
	} catch ( Throwable $error ) {
		$errors[] = 'Could not resolve native payments runtime owner: ' . get_class( $error );
	}
}

echo wp_json_encode(
	array(
		'success'       => empty( $errors ),
		'mode'          => 'probe-runtime-owner',
		'errors'        => $errors,
		'runtime_owner' => $runtime_owner,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'Could not probe native payments runtime owner: %s\n' "$raw" >&2
		return 1
	fi
	printf '%s\n' "$json"
}

bind_runtime_owner() {
	local artifact_path="$1"
	local expected_owner="$2"
	local probe_json

	if ! probe_json="$(probe_runtime_owner)"; then
		return 1
	fi

	python3 - "$artifact_path" "$expected_owner" "$probe_json" <<'PY'
import json
import sys
from pathlib import Path

artifact_path = Path(sys.argv[1])
expected_owner = sys.argv[2]
try:
    artifact = json.loads(artifact_path.read_text(encoding="utf-8"))
    probe = json.loads(sys.argv[3])
except (OSError, UnicodeError, json.JSONDecodeError) as exc:
    raise SystemExit(f"could not bind runtime owner: {exc}")

actual_owner = probe.get("runtime_owner")
artifact["runtime_owner"] = actual_owner
errors = artifact.get("errors")
if not isinstance(errors, list):
    errors = ["Lifecycle evidence returned malformed errors."]
for error in probe.get("errors", []):
    if isinstance(error, str) and error:
        errors.append(error)
if probe.get("success") is not True or actual_owner != expected_owner:
    errors.append(
        f"Payments runtime owner is {actual_owner or '<missing>'}; expected {expected_owner}."
    )
    artifact["success"] = False
artifact["errors"] = list(dict.fromkeys(errors))
artifact_path.write_text(json.dumps(artifact, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

snapshot_restored_runtime_owner() {
	python3 - "$SNAPSHOT_FIXTURE_JSON" <<'PY'
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
print("plugin" if payload.get("was_plugin_active") is True else "native")
PY
}

extract_json_line() {
	grep -E '^\{' | tail -1
}

snapshot_plugin_active_fixture() {
	local raw rc json

	progress "snapshotting plugin-active fixture"
	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval-file - snapshot-plugin-active <<'PHP' 2>&1
<?php
$errors      = array();
$plugin_file = 'woocommerce-payments/woocommerce-payments.php';
$candidates  = array();
$mu_dir      = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if ( is_dir( $mu_dir ) ) {
	$paths = glob( trailingslashit( $mu_dir ) . '*.php' ) ?: array();
	sort( $paths, SORT_STRING );
	foreach ( $paths as $path ) {
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			$errors[] = 'Could not inspect mu-plugin before staging: ' . $path;
			continue;
		}
		if ( false === strpos( $contents, 'woocommerce_native_payments_enabled' ) ) {
			continue;
		}

		$disabled_path = $path . '.disabled-by-woopayments-merge';
		$candidates[]  = array(
			'path'          => $path,
			'disabled_path' => $disabled_path,
			'sha256'        => hash( 'sha256', $contents ),
		);
		if ( file_exists( $disabled_path ) || is_link( $disabled_path ) ) {
			$errors[] = 'Native payments mu-plugin destination already exists: ' . $disabled_path;
		}
	}
}
if ( empty( $candidates ) ) {
	$errors[] = 'No native payments mu-plugin candidates were found for staging.';
}

echo wp_json_encode(
	array(
		'schema'               => 'woopayments_plugin_active_fixture_snapshot.v1',
		'success'              => empty( $errors ),
		'mode'                 => 'snapshot-plugin-active',
		'errors'               => $errors,
		'was_plugin_active'    => is_plugin_active( $plugin_file ),
		'candidate_mu_plugins' => $candidates,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		blocked "could not snapshot plugin-active fixture: $raw"
	fi

	printf '%s\n' "$json" > "$SNAPSHOT_FIXTURE_JSON"
	if ! json_success "$SNAPSHOT_FIXTURE_JSON"; then
		json_errors "$SNAPSHOT_FIXTURE_JSON"
		blocked "could not snapshot plugin-active fixture; see $SNAPSHOT_FIXTURE_JSON"
	fi
}

mutate_plugin_active_fixture() {
	local payload_b64 raw rc json

	progress "mutating plugin-active fixture"
	payload_b64="$(python3 - "$SNAPSHOT_FIXTURE_JSON" <<'PY'
import base64
import sys
from pathlib import Path

print(base64.b64encode(Path(sys.argv[1]).read_bytes()).decode("ascii"))
PY
)"
	if [ -z "$payload_b64" ]; then
		blocked "could not encode plugin-active fixture snapshot: $SNAPSHOT_FIXTURE_JSON"
	fi

	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval-file - mutate-plugin-active "$payload_b64" <<'PHP' 2>&1
<?php
$payload_b64 = isset( $args[1] ) ? (string) $args[1] : '';
$decoded     = base64_decode( $payload_b64, true );
$payload     = false === $decoded ? null : json_decode( $decoded, true );
$errors      = array();
$entries     = array();
$plugin_file = 'woocommerce-payments/woocommerce-payments.php';
$mu_dir      = untrailingslashit( wp_normalize_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) );

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (
	! is_array( $payload ) ||
	'woopayments_plugin_active_fixture_snapshot.v1' !== ( $payload['schema'] ?? '' ) ||
	'snapshot-plugin-active' !== ( $payload['mode'] ?? '' ) ||
	! array_key_exists( 'was_plugin_active', $payload ) ||
	! is_bool( $payload['was_plugin_active'] ) ||
	! isset( $payload['candidate_mu_plugins'] ) ||
	! is_array( $payload['candidate_mu_plugins'] )
) {
	$errors[] = 'Mutation snapshot payload was invalid.';
} else {
	$entries = $payload['candidate_mu_plugins'];
}

$seen_paths = array();
foreach ( $entries as $entry ) {
	$path          = is_array( $entry ) && isset( $entry['path'] ) ? (string) $entry['path'] : '';
	$disabled_path = is_array( $entry ) && isset( $entry['disabled_path'] ) ? (string) $entry['disabled_path'] : '';
	$expected_hash = is_array( $entry ) && isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '';
	$normalized_path = wp_normalize_path( $path );
	$normalized_disabled_path = wp_normalize_path( $disabled_path );
	if (
		'' === $path ||
		$path . '.disabled-by-woopayments-merge' !== $disabled_path ||
		dirname( $normalized_path ) !== $mu_dir ||
		dirname( $normalized_disabled_path ) !== $mu_dir ||
		'.php' !== substr( $normalized_path, -4 ) ||
		1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) ||
		isset( $seen_paths[ $path ] ) ||
		isset( $seen_paths[ $disabled_path ] )
	) {
		$errors[] = 'Mutation snapshot contained an invalid mu-plugin entry.';
		continue;
	}
	$seen_paths[ $path ]          = true;
	$seen_paths[ $disabled_path ] = true;

	if ( ! file_exists( $path ) && ! is_link( $path ) ) {
		$errors[] = 'Native payments mu-plugin disappeared before mutation: ' . $path;
		continue;
	}
	if ( file_exists( $disabled_path ) || is_link( $disabled_path ) ) {
		$errors[] = 'Native payments mu-plugin destination already exists: ' . $disabled_path;
		continue;
	}
	$actual_hash = @hash_file( 'sha256', $path );
	if ( false === $actual_hash || ! hash_equals( $expected_hash, $actual_hash ) ) {
		$errors[] = 'Native payments mu-plugin changed after snapshot: ' . $path;
	}
}

if ( empty( $errors ) ) {
	foreach ( $entries as $entry ) {
		$path          = (string) $entry['path'];
		$disabled_path = (string) $entry['disabled_path'];
		$expected_hash = (string) $entry['sha256'];
		if ( file_exists( $disabled_path ) || is_link( $disabled_path ) ) {
			$errors[] = 'Native payments mu-plugin destination already exists: ' . $disabled_path;
			break;
		}
		if ( ! @rename( $path, $disabled_path ) ) {
			$errors[] = 'Could not disable native payments mu-plugin: ' . $path;
			break;
		}
		$actual_hash = @hash_file( 'sha256', $disabled_path );
		if (
			file_exists( $path ) ||
			is_link( $path ) ||
			false === $actual_hash ||
			! hash_equals( $expected_hash, $actual_hash )
		) {
			$errors[] = 'Could not verify disabled native payments mu-plugin: ' . $disabled_path;
			break;
		}
	}
}

if ( empty( $errors ) && ! is_plugin_active( $plugin_file ) ) {
	$result = activate_plugin( $plugin_file, '', false, true );
	if ( is_wp_error( $result ) ) {
		$errors[] = 'Could not activate WooPayments plugin: ' . $result->get_error_message();
	}
}
if ( ! is_plugin_active( $plugin_file ) ) {
	$errors[] = 'WooPayments plugin is not active after mutation.';
}

echo wp_json_encode(
	array(
		'success'             => empty( $errors ),
		'mode'                => 'mutate-plugin-active',
		'errors'              => $errors,
		'wcpay_plugin_active' => is_plugin_active( $plugin_file ),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		blocked "could not mutate plugin-active fixture: $raw"
	fi

	printf '%s\n' "$json" > "$STAGE_FIXTURE_JSON"
	if ! bind_snapshot_digest "$STAGE_FIXTURE_JSON"; then
		blocked "could not bind plugin-active fixture snapshot to stage evidence."
	fi
	if ! bind_runtime_owner "$STAGE_FIXTURE_JSON" plugin; then
		blocked "could not bind plugin-active fixture runtime owner to stage evidence."
	fi
	if ! json_success "$STAGE_FIXTURE_JSON"; then
		json_errors "$STAGE_FIXTURE_JSON"
		blocked "could not mutate plugin-active fixture; see $STAGE_FIXTURE_JSON"
	fi
}

stage_plugin_active_fixture() {
	snapshot_plugin_active_fixture
	CLEANUP_ARMED=1
	mutate_plugin_active_fixture
}

restore_plugin_active_fixture() {
	local payload_b64 raw rc json expected_owner

	if [ "$CLEANUP_ARMED" -ne 1 ]; then
		return 0
	fi
	if [ -z "$SNAPSHOT_FIXTURE_JSON" ] || [ ! -f "$SNAPSHOT_FIXTURE_JSON" ]; then
		printf 'Plugin-active settings gate: cleanup failed: fixture snapshot is unavailable.\n' >&2
		return 1
	fi

	progress "restoring plugin-active fixture"
	payload_b64="$(python3 - "$SNAPSHOT_FIXTURE_JSON" <<'PY'
import base64
import sys
from pathlib import Path

print(base64.b64encode(Path(sys.argv[1]).read_bytes()).decode("ascii"))
PY
)"
	if [ -z "$payload_b64" ]; then
		printf 'Plugin-active settings gate: cleanup failed: could not encode fixture snapshot.\n' >&2
		return 1
	fi

	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval-file - restore-plugin-active "$payload_b64" <<'PHP' 2>&1
<?php
$payload_b64 = isset( $args[1] ) ? (string) $args[1] : '';
$decoded     = base64_decode( $payload_b64, true );
$payload     = false === $decoded ? null : json_decode( $decoded, true );
$errors      = array();
$entries     = array();
$plugin_file = 'woocommerce-payments/woocommerce-payments.php';
$mu_dir      = untrailingslashit( wp_normalize_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) );
$was_active  = null;

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (
	! is_array( $payload ) ||
	'woopayments_plugin_active_fixture_snapshot.v1' !== ( $payload['schema'] ?? '' ) ||
	'snapshot-plugin-active' !== ( $payload['mode'] ?? '' ) ||
	! array_key_exists( 'was_plugin_active', $payload ) ||
	! is_bool( $payload['was_plugin_active'] ) ||
	! isset( $payload['candidate_mu_plugins'] ) ||
	! is_array( $payload['candidate_mu_plugins'] )
) {
	$errors[] = 'Restore snapshot payload was invalid.';
} else {
	$entries    = $payload['candidate_mu_plugins'];
	$was_active = $payload['was_plugin_active'];
}

$seen_paths = array();
foreach ( $entries as $entry ) {
	$path          = is_array( $entry ) && isset( $entry['path'] ) ? (string) $entry['path'] : '';
	$disabled_path = is_array( $entry ) && isset( $entry['disabled_path'] ) ? (string) $entry['disabled_path'] : '';
	$expected_hash = is_array( $entry ) && isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '';
	$normalized_path = wp_normalize_path( $path );
	$normalized_disabled_path = wp_normalize_path( $disabled_path );
	if (
		'' === $path ||
		$path . '.disabled-by-woopayments-merge' !== $disabled_path ||
		dirname( $normalized_path ) !== $mu_dir ||
		dirname( $normalized_disabled_path ) !== $mu_dir ||
		'.php' !== substr( $normalized_path, -4 ) ||
		1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) ||
		isset( $seen_paths[ $path ] ) ||
		isset( $seen_paths[ $disabled_path ] )
	) {
		$errors[] = 'Restore snapshot contained an invalid mu-plugin entry.';
		continue;
	}
	$seen_paths[ $path ]          = true;
	$seen_paths[ $disabled_path ] = true;

	$path_exists     = file_exists( $path ) || is_link( $path );
	$disabled_exists = file_exists( $disabled_path ) || is_link( $disabled_path );
	if ( $path_exists && $disabled_exists ) {
		$errors[] = 'Both native payments mu-plugin paths exist during restore: ' . $path;
		continue;
	}
	if ( ! $path_exists && ! $disabled_exists ) {
		$errors[] = 'Native payments mu-plugin is missing from both paths: ' . $path;
		continue;
	}

	$current_path = $path_exists ? $path : $disabled_path;
	$actual_hash  = @hash_file( 'sha256', $current_path );
	if ( false === $actual_hash || ! hash_equals( $expected_hash, $actual_hash ) ) {
		$errors[] = 'Native payments mu-plugin hash mismatch during restore: ' . $current_path;
		continue;
	}
	if ( ! $path_exists ) {
		if ( file_exists( $path ) || is_link( $path ) || ! @rename( $disabled_path, $path ) ) {
			$errors[] = 'Could not restore native payments mu-plugin: ' . $path;
		}
	}
}

if ( true === $was_active && ! is_plugin_active( $plugin_file ) ) {
	$result = activate_plugin( $plugin_file, '', false, true );
	if ( is_wp_error( $result ) ) {
		$errors[] = 'Could not reactivate WooPayments plugin: ' . $result->get_error_message();
	}
} elseif ( false === $was_active && is_plugin_active( $plugin_file ) ) {
	deactivate_plugins( $plugin_file, true );
}

foreach ( $entries as $entry ) {
	if ( ! is_array( $entry ) || ! isset( $entry['path'], $entry['disabled_path'], $entry['sha256'] ) ) {
		continue;
	}
	$path          = (string) $entry['path'];
	$disabled_path = (string) $entry['disabled_path'];
	$expected_hash = (string) $entry['sha256'];
	if ( ! file_exists( $path ) && ! is_link( $path ) ) {
		$errors[] = 'Native payments mu-plugin was not restored: ' . $path;
		continue;
	}
	$actual_hash = @hash_file( 'sha256', $path );
	if ( false === $actual_hash || ! hash_equals( $expected_hash, $actual_hash ) ) {
		$errors[] = 'Native payments mu-plugin final hash mismatch: ' . $path;
	}
	if ( file_exists( $disabled_path ) || is_link( $disabled_path ) ) {
		$errors[] = 'Disabled native payments mu-plugin path remains: ' . $disabled_path;
	}
}

if ( is_bool( $was_active ) && $was_active !== is_plugin_active( $plugin_file ) ) {
	$errors[] = 'WooPayments plugin activation state was not restored.';
}

echo wp_json_encode(
	array(
		'success'             => empty( $errors ),
		'mode'                => 'restore-plugin-active',
		'errors'              => array_values( array_unique( $errors ) ),
		'wcpay_plugin_active' => is_plugin_active( $plugin_file ),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'Plugin-active settings gate: cleanup failed: restore command did not return success: %s\n' "$raw" >&2
		return 1
	fi

	printf '%s\n' "$json" > "$RESTORE_FIXTURE_JSON"
	if ! bind_snapshot_digest "$RESTORE_FIXTURE_JSON"; then
		printf 'Plugin-active settings gate: cleanup failed: could not bind fixture snapshot to restore evidence.\n' >&2
		return 1
	fi
	if ! expected_owner="$(snapshot_restored_runtime_owner)"; then
		printf 'Plugin-active settings gate: cleanup failed: could not resolve the expected restored runtime owner.\n' >&2
		return 1
	fi
	if ! bind_runtime_owner "$RESTORE_FIXTURE_JSON" "$expected_owner"; then
		printf 'Plugin-active settings gate: cleanup failed: could not bind fixture runtime owner to restore evidence.\n' >&2
		return 1
	fi
	if ! json_success "$RESTORE_FIXTURE_JSON"; then
		json_errors "$RESTORE_FIXTURE_JSON"
		printf 'Plugin-active settings gate: cleanup failed: restore verification failed; see %s\n' "$RESTORE_FIXTURE_JSON" >&2
		return 1
	fi

	CLEANUP_ARMED=0
	return 0
}

finalize_context_bound_packet() {
	local rollup_path="$OUT_DIR/plugin-active-settings-gate.json"

	if [ -z "$CONTEXT_FILE" ] || [ ! -f "$rollup_path" ]; then
		return 0
	fi

	python3 - "$rollup_path" "$CONTEXT_FILE" "$CONTEXT_SHA256" "$OUT_DIR" "$STAGE_PLUGIN_ACTIVE_FIXTURE" <<'PY'
import hashlib
import json
import re
import sys
from pathlib import Path

rollup_path, context_path, expected_context_sha256, out_dir, staged = sys.argv[1:]
rollup_file = Path(rollup_path).resolve()
packet_dir = Path(out_dir).resolve()

try:
    rollup = json.loads(rollup_file.read_text(encoding="utf-8"))
    context = json.loads(Path(context_path).read_text(encoding="utf-8"))
except (OSError, UnicodeError, json.JSONDecodeError) as exc:
    raise SystemExit(f"could not load context-bound packet input: {exc}")

context_sha256 = context.get("context_sha256")
if not isinstance(context_sha256, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", context_sha256):
    raise SystemExit("aggregate context has an invalid context_sha256")
if context_sha256 != expected_context_sha256:
    raise SystemExit("aggregate context changed during plugin-active capture")

names = {
    "plugin-active-settings-blockers.txt",
    "plugin-active-settings-failures.txt",
    "plugin-active-settings.json",
    "plugin-active-settings.playwriter.log",
    "plugin-active-settings.png",
}
if staged == "1":
    names.update(
        {
            "plugin-active-settings-restore.json",
            "plugin-active-settings-snapshot.json",
            "plugin-active-settings-stage.json",
        }
    )

artifacts = []
for name in sorted(names):
    path = packet_dir / name
    if not path.is_file():
        raise SystemExit(f"context-bound packet artifact is missing: {name}")
    artifacts.append(
        {
            "path": str(path),
            "sha256": "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest(),
        }
    )

rollup["context_sha256"] = context_sha256
rollup["artifacts"] = artifacts
rollup_file.write_text(json.dumps(rollup, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

handle_exit() {
	local exit_code=$?

	trap - EXIT
	trap '' HUP INT TERM
	if ! restore_plugin_active_fixture; then
		printf 'BLOCKED: plugin-active fixture cleanup failed; refusing to report the gate result.\n' >&2
		exit 70
	fi
	if ! finalization_error="$(finalize_context_bound_packet 2>&1)"; then
		printf 'BLOCKED: plugin-active evidence packet finalization failed: %s\n' "$finalization_error" >&2
		exit 70
	fi
	exit "$exit_code"
}

handle_signal() {
	exit "$1"
}

validate_driver_evidence() {
	local evidence_path="$1"

	python3 - "$TARGET_URL" "$SETTINGS_URL" "$evidence_path" <<'PY'
import json
import sys

target_url, settings_url, evidence_path = sys.argv[1:]
errors = []
try:
    with open(evidence_path, encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception as exc:
    raise SystemExit(f"invalid evidence JSON: {exc}")

expected_values = {
    "schema": "woopayments_plugin_active_settings_browser_evidence.v1",
    "target_url": target_url,
    "settings_url": settings_url,
}
for key, expected in expected_values.items():
    if payload.get(key) != expected:
        errors.append(f"{key} mismatch: expected {expected!r}, got {payload.get(key)!r}")

status = payload.get("status")
if status not in {"pass", "fail", "blocked"}:
    errors.append(f"status is not pass/fail/blocked: {status!r}")
elif status == "fail":
    errors.append("browser evidence reported failure status")
if payload.get("plugin_active") is not True:
    errors.append("plugin_active is not true")
if payload.get("authenticated_wp_admin") is not True:
    errors.append("authenticated wp-admin settings page did not render")
if payload.get("settings_screen_present") is not True:
    errors.append("WooPayments settings screen is not present")
script_urls = payload.get("plugin_settings_script_urls")
has_plugin_settings_script = isinstance(script_urls, list) and any(
    isinstance(url, str)
    and "/wp-content/plugins/woocommerce-payments/dist/settings" in url
    and ".js" in url
    for url in script_urls
)
if payload.get("plugin_settings_assets_present") is not True or not has_plugin_settings_script:
    errors.append("standalone WooPayments settings assets were not observed")
if payload.get("plugin_settings_global_present") is not True:
    errors.append("standalone WooPayments settings global was not observed")
if payload.get("native_settings_asset_urls"):
    errors.append("native WooPayments settings assets were observed")
if payload.get("duplicate_store_errors"):
    errors.append("duplicate wc/payments/settings store registration error")
if payload.get("fatal_console_errors"):
    errors.append("fatal browser console errors were captured")
if payload.get("failed_responses"):
    errors.append("failed browser responses were captured")
for failure in payload.get("failures", []):
    errors.append(str(failure))

blockers = [str(blocker) for blocker in payload.get("blockers", []) if str(blocker).strip()]
if status == "blocked" and not blockers:
    errors.append("blocked browser evidence did not include blocker details")
elif status == "pass" and blockers:
    errors.append("passing browser evidence included blocker details")

errors = list(dict.fromkeys(errors))
for error in errors:
    print(error)

if errors:
    raise SystemExit(1)

if status == "blocked":
    for blocker in blockers:
        print(blocker)
    raise SystemExit(3)
PY
}

write_rollup() {
	local rollup_path="$OUT_DIR/plugin-active-settings-gate.json"
	local evidence_path="$1"

	python3 - "$rollup_path" "$TARGET_URL" "$SETTINGS_URL" "$evidence_path" "$FAILURES_FILE" "$BLOCKERS_FILE" "$RUNNER_ROLE" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, target_url, settings_url, evidence_path, failures_file, blockers_file, runner_role = sys.argv[1:]

def load_json(path):
    candidate = Path(path)
    if not candidate.exists():
        return None
    return json.loads(candidate.read_text(encoding="utf-8"))

failures_path = Path(failures_file)
failures = []
if failures_path.exists():
    failures = [line for line in failures_path.read_text(encoding="utf-8").splitlines() if line.strip()]

blockers_path = Path(blockers_file)
blockers = []
if blockers_path.exists():
    blockers = [line for line in blockers_path.read_text(encoding="utf-8").splitlines() if line.strip()]

evidence = load_json(evidence_path)
evidence_status = evidence.get("status") if isinstance(evidence, dict) else None
if evidence_status == "fail" and not failures:
    failures.append("browser evidence reported failure status")
elif evidence_status not in {"pass", "blocked", "fail"} and not failures:
    failures.append("browser evidence status is missing or invalid")
elif evidence_status == "blocked" and not blockers and not failures:
    failures.append("blocked browser evidence did not include blocker details")

payload = {
    "schema": "woopayments_plugin_active_settings_gate_rollup.v1",
    "status": "fail" if failures else "blocked" if blockers else "pass",
    "target_url": target_url,
    "settings_url": settings_url,
    "runner_role": runner_role,
    "evidence": evidence,
    "failures": failures,
    "blockers": blockers,
}
Path(rollup_path).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

run_browser_gate() {
	local evidence_path="$OUT_DIR/plugin-active-settings.json"
	local log_path="$OUT_DIR/plugin-active-settings.playwriter.log"
	local exit_code
	local validation_output
	local validation_code
	local browser_config_js

	rm -f "$evidence_path"
	progress "driving settings page at $SETTINGS_URL"

	browser_config_js="$(
		python3 - "$TARGET_URL" "$SETTINGS_URL" "$evidence_path" "$OUT_DIR" <<'PY'
import json
import sys

target_url, settings_url, evidence_path, data_dir = sys.argv[1:]
print(
    "state.pluginActiveSettingsConfig = "
    + json.dumps(
        {
            "targetUrl": target_url,
            "settingsUrl": settings_url,
            "evidencePath": evidence_path,
            "dataDir": data_dir,
        },
        sort_keys=True,
    )
    + ";"
)
PY
	)"

	if [ "$BROWSER_RUNNER" = "playwriter" ]; then
		"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -e "$browser_config_js" --timeout "30000" >"$log_path" 2>&1
		exit_code=$?
		if [ "$exit_code" -ne 0 ]; then
			record_failure "Playwriter config seed exited $exit_code; see $log_path"
			write_rollup "$evidence_path"
			return
		fi

		PLUGIN_SETTINGS_TARGET_URL="$TARGET_URL" \
		PLUGIN_SETTINGS_SETTINGS_URL="$SETTINGS_URL" \
		PLUGIN_SETTINGS_EVIDENCE_PATH="$evidence_path" \
		PLUGIN_SETTINGS_DATA_DIR="$OUT_DIR" \
		"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -f "$BROWSER_DRIVER" --timeout "180000" >>"$log_path" 2>&1
	else
		PLUGIN_SETTINGS_TARGET_URL="$TARGET_URL" \
		PLUGIN_SETTINGS_SETTINGS_URL="$SETTINGS_URL" \
		PLUGIN_SETTINGS_EVIDENCE_PATH="$evidence_path" \
		PLUGIN_SETTINGS_DATA_DIR="$OUT_DIR" \
		WP_ADMIN_USER="$WP_ADMIN_USER" \
		WP_ADMIN_PASSWORD="$WP_ADMIN_PASSWORD" \
		"${PLAYWRIGHT_RUNNER_CMD[@]}" "$BROWSER_DRIVER" --timeout "180000" >"$log_path" 2>&1
	fi
	exit_code=$?

	if [ "$exit_code" -ne 0 ]; then
		record_failure "$BROWSER_RUNNER exited $exit_code; see $log_path"
	fi
	if [ ! -f "$evidence_path" ]; then
		record_failure "missing browser evidence $evidence_path"
		write_rollup "$evidence_path"
		return
	fi

	validation_output="$(validate_driver_evidence "$evidence_path" 2>&1)"
	validation_code=$?
	if [ "$validation_code" -eq 3 ]; then
		while IFS= read -r line; do
			if [ -n "$line" ]; then
				record_blocker "$line"
			fi
		done <<< "$validation_output"
	elif [ "$validation_code" -ne 0 ]; then
		while IFS= read -r line; do
			if [ -n "$line" ]; then
				record_failure "$line"
			fi
		done <<< "$validation_output"
	fi

	write_rollup "$evidence_path"
}

if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi
URL_VALIDATION_OUTPUT="$(validate_local_url "target URL" "$TARGET_URL" 2>&1)"
if [ "$?" -ne 0 ]; then
	blocked "$URL_VALIDATION_OUTPUT"
fi

if [ "$PRINT_PLAN" -eq 1 ]; then
	print_plan
	exit 0
fi

if [ ! -f "$BROWSER_DRIVER" ]; then
	blocked "browser driver is missing: $BROWSER_DRIVER"
fi

if [ "$BROWSER_RUNNER" = "playwriter" ]; then
	if [ -n "${PLAYWRITER_BIN:-}" ]; then
		# shellcheck disable=SC2206
		PLAYWRITER_CMD=( $PLAYWRITER_BIN )
	elif command -v playwriter >/dev/null 2>&1; then
		PLAYWRITER_CMD=( playwriter )
	elif command -v npx >/dev/null 2>&1; then
		PLAYWRITER_CMD=( npx --yes playwriter@latest )
	else
		blocked "Playwriter is required. Install playwriter or provide PLAYWRITER_BIN."
	fi
else
	# shellcheck disable=SC2206
	PLAYWRIGHT_RUNNER_CMD=( $PLAYWRIGHT_SCRIPT_RUNNER_BIN )
fi

mkdir -p "$OUT_DIR" || blocked "could not create evidence output directory: $OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
FAILURES_FILE="$OUT_DIR/plugin-active-settings-failures.txt"
BLOCKERS_FILE="$OUT_DIR/plugin-active-settings-blockers.txt"
SNAPSHOT_FIXTURE_JSON="$OUT_DIR/plugin-active-settings-snapshot.json"
STAGE_FIXTURE_JSON="$OUT_DIR/plugin-active-settings-stage.json"
RESTORE_FIXTURE_JSON="$OUT_DIR/plugin-active-settings-restore.json"
: > "$FAILURES_FILE"
: > "$BLOCKERS_FILE"

trap handle_exit EXIT
trap 'handle_signal 129' HUP
trap 'handle_signal 130' INT
trap 'handle_signal 143' TERM

if [ "$STAGE_PLUGIN_ACTIVE_FIXTURE" -eq 1 ]; then
	stage_plugin_active_fixture
fi

assert_plugin_active

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
	progress "preflight ok for plugin-active settings gate."
	exit 0
fi

if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_SESSION" ]; then
	blocked "pass --playwriter-session or set PLAYWRITER_SESSION before running plugin-active settings browser flow."
fi

run_browser_gate || blocked "could not run plugin-active settings browser gate."

if [ -s "$FAILURES_FILE" ]; then
	while IFS= read -r failure_message; do
		if [ -n "$failure_message" ]; then
			printf 'FAIL: %s\n' "$failure_message" >&2
		fi
	done < "$FAILURES_FILE"
	exit 1
fi

if [ -s "$BLOCKERS_FILE" ]; then
	while IFS= read -r blocker_message; do
		if [ -n "$blocker_message" ]; then
			printf 'BLOCKED: %s\n' "$blocker_message" >&2
		fi
	done < "$BLOCKERS_FILE"
	exit 3
fi

progress "wrote passing browser evidence rollup: $OUT_DIR/plugin-active-settings-gate.json"
