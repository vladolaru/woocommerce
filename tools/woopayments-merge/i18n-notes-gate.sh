#!/usr/bin/env bash
#
# Native WooPayments translated order-notes gate.
#
# Snapshot mode validates a captured notes payload. Live mode switches the target
# store to de_DE, drives native charge/refund/dispute flows, captures order notes,
# validates them, and restores the original site language.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	echo "FAIL: local runner safety library is missing: $LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"
FLOW_DRIVE="$SELF_DIR/flow-drive.sh"

TARGET_WP=""
STATE=""
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/i18n-notes-gate"
LOCALE="de_DE"
PRINT_PLAN=0
POLL_TRIES="${I18N_NOTES_POLL_TRIES:-60}"
POLL_SLEEP_SECONDS="${I18N_NOTES_POLL_SLEEP_SECONDS:-1}"
WP_CLI_MEMORY_LIMIT="${I18N_NOTES_WP_CLI_MEMORY_LIMIT:-256M}"
TARGET_WP_RUNTIME=""
ORIGINAL_WPLANG=""
LANGUAGE_CLEANUP_ARMED=0
LANGUAGE_SNAPSHOT_JSON=""
LANGUAGE_RESTORE_JSON=""
PROBE_CLEANUP_ARMED=0
I18N_PROBE_TOKEN=""
PROBE_RESTORE_JSON=""
TRANSLATION_PROBE_PLUGIN="woopayments-i18n-notes-gate-translations.php"
CATALOG_EVIDENCE=""

usage() {
	cat >&2 <<'USAGE'
usage:
  i18n-notes-gate.sh --target "<target wp>" [options]
  i18n-notes-gate.sh --state <file> [options]

Options:
  --target "<wp>"          Target native store WP-CLI command.
  --state <file>           Pre-captured JSON note snapshot.
  --locale <locale>        Locale to validate in live mode (default: de_DE).
  --out-dir <path>         Evidence output directory.
  --print-plan             Print the live i18n note probe plan as JSON, then exit.
  -h, --help               Show this help.
USAGE
}

usage_error() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

blocked() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 3
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--state=*) STATE="${1#--state=}"; shift ;;
		--state) STATE="${2:-}"; shift 2 ;;
		--locale=*) LOCALE="${1#--locale=}"; shift ;;
		--locale) LOCALE="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

print_plan() {
	python3 - "$TARGET_WP" "$OUT_DIR" "$LOCALE" "$FLOW_DRIVE" "$WP_CLI_MEMORY_LIMIT" <<'PY'
import json
import sys

target_wp, out_dir, locale, flow_drive, wp_cli_memory_limit = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_i18n_notes_gate_plan.v1",
            "target_wp": target_wp,
            "out_dir": out_dir,
            "locale": locale,
            "flow_driver": flow_drive,
            "wp_cli_memory_limit": wp_cli_memory_limit,
            "required_flows": ["charge", "refund", "dispute"],
            "translation_probe": {
                "plugin": "woopayments-i18n-notes-gate-translations.php",
                "purpose": "proves exact WooCommerce message IDs and text domain usage with deterministic per-flow markers",
            },
            "catalog_probe": "records actual release language-pack coverage before the deterministic probe is installed",
            "english_sentinels": [
                "Payment complete.",
                "Payment failed.",
                "Payment authorization expired.",
                "Fee details:",
                "Fee (",
                "Base fee:",
                "Currency conversion fee:",
                "Net payout:",
                "The refund returned status",
                "Refunded",
                "Payment dispute and fees have been deducted",
                "Payment dispute funds have been reinstated",
                "Payment dispute has been updated",
                "dispute overview",
                "Payment has been disputed",
                "Payment inquiry has been raised",
                "A test payment",
            ],
        },
        sort_keys=True,
    )
)
PY
}

if ! [[ "$WP_CLI_MEMORY_LIMIT" =~ ^[1-9][0-9]*[MmGg]$ ]]; then
	usage_error "I18N_NOTES_WP_CLI_MEMORY_LIMIT must be a positive M/G PHP memory limit."
fi

if [ "$PRINT_PLAN" -eq 1 ]; then
	if [ -z "$TARGET_WP" ]; then
		usage_error "--target is required with --print-plan."
	fi
	print_plan
	exit 0
fi

if [ -n "$STATE" ]; then
	if [ ! -f "$STATE" ]; then
		usage_error "state file not found: $STATE"
	fi
else
	if [ -z "$TARGET_WP" ]; then
		usage_error "provide --target or --state."
	fi
	# Local-only enforcement (RULE: never drive live-mode flows against a remote store).
	# Validated before any store command in live mode; snapshot (--state) and
	# --print-plan modes run no store commands.
	if ! runner_error="$(woopayments_validate_local_wp_runner "$TARGET_WP")"; then
		printf 'FAIL: unsafe WP-CLI command for --target: %s\n' "$runner_error" >&2
		exit 2
	fi
	TARGET_WP_RUNTIME="$TARGET_WP --exec=ini_set(\"memory_limit\",\"$WP_CLI_MEMORY_LIMIT\");"
fi

if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi

case "$POLL_TRIES:$POLL_SLEEP_SECONDS" in
	*[!0-9:]*|:*|*:)
		usage_error "I18N_NOTES_POLL_TRIES and I18N_NOTES_POLL_SLEEP_SECONDS must be non-negative integers."
		;;
esac

mkdir -p "$OUT_DIR"
LANGUAGE_SNAPSHOT_JSON="$OUT_DIR/i18n-language-snapshot.json"
LANGUAGE_RESTORE_JSON="$OUT_DIR/i18n-language-restore.json"
PROBE_RESTORE_JSON="$OUT_DIR/i18n-probe-cleanup.json"

json_from_text() {
	python3 -c '
import json
import sys

raw = sys.stdin.read()
for line in reversed(raw.splitlines()):
    candidate = line.strip()
    if not candidate.startswith("{"):
        continue
    try:
        json.loads(candidate)
    except json.JSONDecodeError:
        continue
    print(candidate)
    sys.exit(0)
sys.exit(1)
'
}

json_file_field() {
	python3 -c '
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    data = json.load(stream)
value = data.get(sys.argv[2], "")
if isinstance(value, bool):
    print("true" if value else "false")
elif value is not None:
    print(value)
' "$1" "$2"
}

state_has_required_notes() {
	python3 - "$1" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    data = json.load(stream)

markers = {
    "charge": "[wcpay-i18n:charge]",
    "refund": "[wcpay-i18n:refund]",
    "dispute": "[wcpay-i18n:dispute]",
}
flows = set()
for order in data.get("orders", []):
    flow = str(order.get("flow", ""))
    marker = markers.get(flow, "")
    if marker and any(marker in str(note) for note in order.get("notes", [])):
        flows.add(flow)
sys.exit(0 if {"charge", "refund", "dispute"} <= flows else 1)
PY
}

snapshot_language_state() {
	local raw rc json

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME eval-file - <<'PHP' 2>&1
<?php
global $wpdb;

$missing  = new stdClass();
$value    = get_option( 'WPLANG', $missing );
$exists   = $missing !== $value;
$autoload = null;
if ( $exists ) {
	$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'WPLANG' ) );
}

WP_CLI::line( 'WPLANG:' . ( $exists && is_scalar( $value ) ? (string) $value : '' ) );
WP_CLI::line(
	wp_json_encode(
		array(
			'schema'   => 'woopayments_i18n_language_snapshot.v1',
			'success'  => true,
			'exists'   => $exists,
			'value'    => $exists ? $value : null,
			'autoload' => null === $autoload ? null : (string) $autoload,
		)
	)
);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"
	ORIGINAL_WPLANG="$(printf '%s\n' "$raw" | grep -oE 'WPLANG:[A-Za-z_]*' | tail -1 | cut -d: -f2-)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not snapshot original site language."
	fi
	printf '%s\n' "$json" > "$LANGUAGE_SNAPSHOT_JSON"
}

restore_language_state() {
	local payload_b64 raw rc json

	if [ "$LANGUAGE_CLEANUP_ARMED" -ne 1 ]; then
		return 0
	fi
	if [ ! -s "$LANGUAGE_SNAPSHOT_JSON" ]; then
		printf 'i18n notes gate: language cleanup snapshot is missing.\n' >&2
		return 1
	fi

	payload_b64="$(python3 - "$LANGUAGE_SNAPSHOT_JSON" <<'PY'
import base64
import sys
from pathlib import Path

print(base64.b64encode(Path(sys.argv[1]).read_bytes()).decode("ascii"))
PY
)"
	if [ -z "$payload_b64" ]; then
		printf 'i18n notes gate: could not encode language cleanup snapshot.\n' >&2
		return 1
	fi

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME eval-file - "$payload_b64" --skip-plugins --skip-themes <<'PHP' 2>&1
<?php
global $wpdb;

$decoded  = base64_decode( (string) ( $args[0] ?? '' ), true );
$snapshot = false === $decoded ? null : json_decode( $decoded, true );
$errors   = array();

if (
	! is_array( $snapshot ) ||
	'woopayments_i18n_language_snapshot.v1' !== ( $snapshot['schema'] ?? '' ) ||
	true !== ( $snapshot['success'] ?? false ) ||
	! array_key_exists( 'exists', $snapshot ) ||
	! is_bool( $snapshot['exists'] ) ||
	! array_key_exists( 'value', $snapshot ) ||
	! array_key_exists( 'autoload', $snapshot )
) {
	$errors[] = 'Language snapshot payload is invalid.';
} elseif ( $snapshot['exists'] ) {
	update_option( 'WPLANG', $snapshot['value'] );
	if ( null !== $snapshot['autoload'] ) {
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => (string) $snapshot['autoload'] ),
			array( 'option_name' => 'WPLANG' )
		);
		wp_cache_delete( 'alloptions', 'options' );
	}
} else {
	delete_option( 'WPLANG' );
}
wp_cache_delete( 'WPLANG', 'options' );

if ( empty( $errors ) ) {
	$missing        = new stdClass();
	$restored_value = get_option( 'WPLANG', $missing );
	$restored_exists = $missing !== $restored_value;
	$restored_autoload = null;
	if ( $restored_exists ) {
		$restored_autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'WPLANG' ) );
	}
	if (
		$restored_exists !== $snapshot['exists'] ||
		( $restored_exists && $restored_value !== $snapshot['value'] ) ||
		( null === $restored_autoload ? null : (string) $restored_autoload ) !== $snapshot['autoload']
	) {
		$errors[] = 'Language option restore verification failed.';
	}
}

WP_CLI::line(
	wp_json_encode(
		array(
			'schema'                  => 'woopayments_i18n_language_restore.v1',
			'success'                 => empty( $errors ),
			'restored_snapshot_exact' => empty( $errors ),
			'errors'                  => $errors,
		)
	)
);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'i18n notes gate: language restore command failed: %s\n' "$raw" >&2
		return 1
	fi
	printf '%s\n' "$json" > "$LANGUAGE_RESTORE_JSON"
	if [ "$(json_file_field "$LANGUAGE_RESTORE_JSON" success)" != "true" ] || [ "$(json_file_field "$LANGUAGE_RESTORE_JSON" restored_snapshot_exact)" != "true" ]; then
		printf 'i18n notes gate: exact language restore was not verified; see %s.\n' "$LANGUAGE_RESTORE_JSON" >&2
		return 1
	fi

	LANGUAGE_CLEANUP_ARMED=0
	return 0
}

assert_target_native_owner() {
	local raw rc owner

	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME wc-native-payments status 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not read target native payments status."
	fi

	owner="$(printf '%s\n' "$raw" | awk -F': ' '/^Owner:/ { print $2; exit }' | tr -d '\r')"
	if [ "$owner" != "native" ]; then
		blocked "target native payments owner is not native: ${owner:-unknown}"
	fi
}

switch_language() {
	local raw rc

	snapshot_language_state
	LANGUAGE_CLEANUP_ARMED=1

	printf 'i18n notes gate: switching target site language to %s...\n' "$LOCALE" >&2
	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME language core install "$LOCALE" 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not install language pack $LOCALE."
	fi

	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME site switch-language "$LOCALE" 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not switch site language to $LOCALE."
	fi

}

capture_catalog_evidence() {
	local out_file="$1"
	local raw rc json

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME eval-file - <<'PHP' 2>&1
<?php
$messages = array(
	'charge'  => '<strong>Fee details:</strong>',
	'refund'  => 'A refund of %1$s %4$s using %2$s (%3$s).',
	'dispute' => 'Payment dispute has been updated',
);
$translated_messages = array();

foreach ( $messages as $flow => $message_id ) {
	$translation = translate( $message_id, 'woocommerce' );
	$translated_messages[ $flow ] = array(
		'message_id'  => $message_id,
		'translation' => $translation,
		'translated'  => $translation !== $message_id,
	);
}

WP_CLI::line(
	wp_json_encode(
		array(
			'schema'            => 'woopayments_i18n_catalog_evidence.v1',
			'locale'            => get_locale(),
			'textdomain'        => 'woocommerce',
			'textdomain_loaded' => is_textdomain_loaded( 'woocommerce' ),
			'messages'          => $translated_messages,
		),
		JSON_UNESCAPED_SLASHES
	)
);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not capture WooCommerce catalog evidence."
	fi

	printf '%s\n' "$json" > "$out_file"
}

attach_translation_evidence() {
	local state_file="$1"
	local catalog_file="$2"

	python3 - "$state_file" "$catalog_file" <<'PY'
import json
import sys
from pathlib import Path

state_path = Path(sys.argv[1])
catalog_path = Path(sys.argv[2])
state = json.loads(state_path.read_text(encoding="utf-8"))
state["translation_source"] = "deterministic_gettext_probe"
state["catalog_evidence"] = json.loads(catalog_path.read_text(encoding="utf-8"))
state_path.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n", encoding="utf-8")
PY
}

assert_translation_probe_path_available() {
	local raw rc json

	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME eval-file - <<PHP 2>&1
<?php
\$plugin = WPMU_PLUGIN_DIR . '/' . '$TRANSLATION_PROBE_PLUGIN';
WP_CLI::line(
	wp_json_encode(
		array(
			'success' => ! file_exists( \$plugin ) && ! is_link( \$plugin ),
			'path'    => \$plugin,
			'errors'  => file_exists( \$plugin ) || is_link( \$plugin ) ? array( 'Translation probe path already exists.' ) : array(),
		)
	)
);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ] || [ "$(printf '%s\n' "$json" | python3 -c 'import json,sys; print("true" if json.load(sys.stdin).get("success") is True else "false")')" != "true" ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "translation probe path is not available."
	fi
}

restore_translation_probe() {
	local raw rc json

	if [ "$PROBE_CLEANUP_ARMED" -ne 1 ]; then
		return 0
	fi

	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME eval-file - "$I18N_PROBE_TOKEN" --skip-plugins --skip-themes <<PHP 2>&1
<?php
\$token  = (string) ( \$args[0] ?? '' );
\$plugin = WPMU_PLUGIN_DIR . '/' . '$TRANSLATION_PROBE_PLUGIN';
\$errors = array();

if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', \$token ) ) {
	\$errors[] = 'Translation probe cleanup token is invalid.';
} elseif ( file_exists( \$plugin ) || is_link( \$plugin ) ) {
	\$contents = @file_get_contents( \$plugin );
	\$marker   = 'woopayments-i18n-probe:' . \$token;
	if ( false === \$contents || false === strpos( \$contents, \$marker ) ) {
		\$errors[] = 'translation probe ownership mismatch';
	} elseif ( ! @unlink( \$plugin ) ) {
		\$errors[] = 'Could not remove owned translation probe.';
	}
}

if ( empty( \$errors ) && ( file_exists( \$plugin ) || is_link( \$plugin ) ) ) {
	\$errors[] = 'Owned translation probe still exists after cleanup.';
}

WP_CLI::line(
	wp_json_encode(
		array(
			'schema'  => 'woopayments_i18n_probe_cleanup.v1',
			'success' => empty( \$errors ),
			'errors'  => \$errors,
		)
	)
);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'i18n notes gate: translation probe cleanup command failed: %s\n' "$raw" >&2
		return 1
	fi
	printf '%s\n' "$json" > "$PROBE_RESTORE_JSON"
	if [ "$(json_file_field "$PROBE_RESTORE_JSON" success)" != "true" ]; then
		printf 'i18n notes gate: translation probe cleanup failed; see %s.\n' "$PROBE_RESTORE_JSON" >&2
		return 1
	fi

	PROBE_CLEANUP_ARMED=0
	return 0
}

install_translation_probe() {
	local raw rc json

	assert_translation_probe_path_available
	I18N_PROBE_TOKEN="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
	if ! [[ "$I18N_PROBE_TOKEN" =~ ^[a-f0-9]{32}$ ]]; then
		blocked "could not generate translation probe ownership token."
	fi
	PROBE_CLEANUP_ARMED=1

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME eval-file - "$I18N_PROBE_TOKEN" <<PHP 2>&1
<?php
\$token  = (string) ( \$args[0] ?? '' );
\$plugin = WPMU_PLUGIN_DIR . '/' . '$TRANSLATION_PROBE_PLUGIN';
if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', \$token ) ) {
	WP_CLI::error( 'Invalid i18n translation probe token.' );
}
if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
	WP_CLI::error( 'Could not create mu-plugins directory.' );
}

\$source = <<<'MU_PLUGIN'
<?php
/**
 * Temporary gettext replacements for the native WooPayments i18n notes gate.
 */

add_filter(
	'gettext',
	static function ( \$translation, \$text, \$domain ) {
		if ( 'woocommerce' !== \$domain ) {
			return \$translation;
		}

		\$map = array(
			'Payment complete.' => '[wcpay-i18n:charge] Zahlung abgeschlossen.',
			'<strong>Fee details:</strong>' => '<strong>[wcpay-i18n:charge] Gebuehrendetails:</strong>',
			'Fee (%1\$s): %2\$s' => 'Gebuehr (%1\$s): %2\$s',
			'Fee: %1\$s' => 'Gebuehr: %1\$s',
			'Base fee: %1\$s' => 'Grundgebuehr: %1\$s',
			'Currency conversion fee: %1\$s' => 'Waehrungsumrechnungsgebuehr: %1\$s',
			'Net payout: %1\$s' => 'Nettoauszahlung: %1\$s',
			'Refunded order' => '[wcpay-i18n:refund] Rueckerstattete Bestellung',
			'A refund of %1\$s %4\$s using %2\$s (%3\$s).' => '[wcpay-i18n:refund] Eine Rueckerstattung von %1\$s %4\$s mit %2\$s (%3\$s).',
			'A refund of %1\$s %5\$s using %2\$s. Reason: %3\$s. (%4\$s)' => '[wcpay-i18n:refund] Eine Rueckerstattung von %1\$s %5\$s mit %2\$s. Grund: %3\$s. (%4\$s)',
			'A refund of %1\$s was <strong>%2\$s</strong> using %3\$s (<code>%4\$s</code>)%5\$s' => '[wcpay-i18n:refund] Eine Rueckerstattung von %1\$s war <strong>%2\$s</strong> mit %3\$s (<code>%4\$s</code>)%5\$s',
			'was successfully processed' => 'wurde erfolgreich verarbeitet',
			'is pending' => 'ist ausstehend',
			'cancelled' => 'abgebrochen',
			'unsuccessful' => 'nicht erfolgreich',
			'Payment dispute and fees have been deducted from your next payout' => '[wcpay-i18n:dispute] Zahlungsdisput und Gebuehren wurden von Ihrer naechsten Auszahlung abgezogen',
			'Payment dispute funds have been reinstated' => '[wcpay-i18n:dispute] Zahlungsdisputmittel wurden wiederhergestellt',
			'Payment dispute has been updated' => '[wcpay-i18n:dispute] Zahlungsdisput wurde aktualisiert',
			'%1\$s. See <a href="%2\$s">dispute overview</a> for more details.' => '%1\$s. Weitere Details in der <a href="%2\$s">Disputuebersicht</a>.',
			'A payment inquiry has been raised for %1\$s with reason "%2\$s". <a href="%4\$s" target="_blank" rel="noopener noreferrer">Response due by %3\$s</a>.' => '[wcpay-i18n:dispute] Eine Zahlungsanfrage ueber %1\$s wurde mit Grund "%2\$s" erstellt. <a href="%4\$s" target="_blank" rel="noopener noreferrer">Antwort faellig bis %3\$s</a>.',
			'Payment has been disputed for %1\$s with reason "%2\$s". <a href="%4\$s" target="_blank" rel="noopener noreferrer">Response due by %3\$s</a>.' => '[wcpay-i18n:dispute] Zahlung ueber %1\$s wurde mit Grund "%2\$s" angefochten. <a href="%4\$s" target="_blank" rel="noopener noreferrer">Antwort faellig bis %3\$s</a>.',
			'Payment inquiry has been closed with status %1\$s. See <a href="%2\$s" target="_blank" rel="noopener noreferrer">payment status</a> for more details.' => '[wcpay-i18n:dispute] Zahlungsanfrage wurde mit Status %1\$s geschlossen. Weitere Details im <a href="%2\$s" target="_blank" rel="noopener noreferrer">Zahlungsstatus</a>.',
			'Dispute has been closed with status %1\$s. See <a href="%2\$s" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details.' => '[wcpay-i18n:dispute] Disput wurde mit Status %1\$s geschlossen. Weitere Details in der <a href="%2\$s" target="_blank" rel="noopener noreferrer">Disputuebersicht</a>.',
		);

		return \$map[ \$text ] ?? \$translation;
	},
	10,
	3
);
MU_PLUGIN;

\$source = "<?php\n// woopayments-i18n-probe:{\$token}\n" . substr( \$source, 6 );
\$handle = @fopen( \$plugin, 'x' );
if ( false === \$handle ) {
	WP_CLI::error( 'Could not exclusively create i18n notes gate translation probe.' );
}
\$written = fwrite( \$handle, \$source );
if ( false === \$written || strlen( \$source ) !== \$written || ! fflush( \$handle ) ) {
	fclose( \$handle );
	WP_CLI::error( 'Could not write i18n notes gate translation probe.' );
}
fclose( \$handle );

WP_CLI::line(
	wp_json_encode(
		array(
			'schema'  => 'woopayments_i18n_probe_install.v1',
			'success' => true,
			'path'    => \$plugin,
			'sha256'  => hash( 'sha256', \$source ),
		)
	)
);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not install i18n translation probe."
	fi
}

handle_exit() {
	local exit_code=$?
	local cleanup_failed=0

	trap - EXIT
	trap '' HUP INT TERM
	if ! restore_translation_probe; then
		cleanup_failed=1
	fi
	if ! restore_language_state; then
		cleanup_failed=1
	fi
	if [ "$cleanup_failed" -ne 0 ]; then
		printf 'FAIL: i18n notes gate cleanup failed; refusing to report the gate result.\n' >&2
		exit 70
	fi
	exit "$exit_code"
}

handle_signal() {
	exit "$1"
}

drive_flow() {
	local flow="$1"
	local out_file="$2"
	shift 2

	local raw rc json

	printf 'i18n notes gate: driving %s flow...\n' "$flow" >&2
	raw="$(WP="$TARGET_WP_RUNTIME" bash "$FLOW_DRIVE" "$@" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'BLOCKED: %s flow did not complete.\n' "$flow" >&2
		printf '%s\n' "$raw" | tail -20 >&2
		exit 3
	fi

	printf '%s\n' "$json" > "$out_file"
}

capture_notes() {
	local out_file="$1"
	shift

	local raw rc json

	# shellcheck disable=SC2086
	raw="$($TARGET_WP_RUNTIME eval-file - "$@" <<'PHP' 2>&1
<?php
$orders = array();

foreach ( $args as $spec ) {
	$parts    = explode( ':', (string) $spec, 2 );
	$flow     = $parts[0] ?? '';
	$order_id = isset( $parts[1] ) ? (int) $parts[1] : 0;
	$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
	$notes    = array();

	if ( $order instanceof WC_Order ) {
		foreach (
			wc_get_order_notes(
				array(
					'order_id' => $order->get_id(),
					'limit'    => 50,
					'orderby'  => 'date_created',
					'order'    => 'ASC',
				)
			) as $note
		) {
			$notes[] = isset( $note->content ) ? trim( (string) $note->content ) : '';
		}
	}

	$orders[] = array(
		'flow'     => $flow,
		'order_id' => $order_id,
		'notes'    => array_values( array_filter( $notes, 'strlen' ) ),
	);
}

WP_CLI::line(
	wp_json_encode(
		array(
			'schema' => 'woopayments_i18n_notes_capture.v1',
			'locale' => get_locale(),
			'orders' => $orders,
		)
	)
);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'BLOCKED: could not capture order notes.\n' >&2
		printf '%s\n' "$raw" | tail -20 >&2
		exit 3
	fi

	printf '%s\n' "$json" > "$out_file"
}

poll_notes() {
	local state_file="$1"
	shift

	local attempt

	attempt=1
	while [ "$attempt" -le "$POLL_TRIES" ]; do
		capture_notes "$state_file" "$@"
		if state_has_required_notes "$state_file"; then
			return 0
		fi

		printf 'i18n notes gate: required notes not complete yet (%s/%s).\n' "$attempt" "$POLL_TRIES" >&2
		if [ "$attempt" -lt "$POLL_TRIES" ]; then
			sleep "$POLL_SLEEP_SECONDS"
		fi
		attempt=$((attempt + 1))
	done

	return 0
}

validate_state() {
	python3 - "$1" "$OUT_DIR/i18n-notes-gate.json" "$LOCALE" <<'PY'
from __future__ import annotations

import json
import re
import sys
from pathlib import Path


REQUIRED_FLOWS = ["charge", "refund", "dispute"]
FLOW_LOCALIZED_PATTERNS = {
    "charge": [r"Gebuehrendetails", r"Zahlung abgeschlossen"],
    "refund": [r"Rueckerstattung"],
    "dispute": [r"Zahlungsanfrage", r"Zahlungsdisput", r"Zahlungsstreitigkeit", r"Disput"],
}
FLOW_PROBE_MARKERS = {
    "charge": "[wcpay-i18n:charge]",
    "refund": "[wcpay-i18n:refund]",
    "dispute": "[wcpay-i18n:dispute]",
}
CATALOG_MESSAGE_IDS = {
    "charge": "<strong>Fee details:</strong>",
    "refund": "A refund of %1$s %4$s using %2$s (%3$s).",
    "dispute": "Payment dispute has been updated",
}
ENGLISH_SENTINELS = [
    "Payment complete.",
    "Payment failed.",
    "Payment authorization expired.",
    "Fee details:",
    "Fee (",
    "Base fee:",
    "Currency conversion fee:",
    "Net payout:",
    "The refund returned status",
    "Refunded",
    "Payment dispute and fees have been deducted",
    "Payment dispute funds have been reinstated",
    "Payment dispute has been updated",
    "dispute overview",
    "Payment has been disputed",
    "Payment inquiry has been raised",
    "A test payment",
]


def normalize_note(note: object) -> str:
    return re.sub(r"\s+", " ", str(note)).strip()


state_path = Path(sys.argv[1])
rollup_path = Path(sys.argv[2])
expected_locale = sys.argv[3]

with state_path.open(encoding="utf-8") as stream:
    state = json.load(stream)

failures: list[str] = []
blockers: list[str] = []

if state.get("schema") != "woopayments_i18n_notes_capture.v1":
    failures.append(f"unexpected state schema: {state.get('schema')}")

captured_locale = str(state.get("locale", ""))
if captured_locale != expected_locale:
    failures.append(f"captured locale {captured_locale} does not match expected {expected_locale}")

orders = state.get("orders", [])
if not isinstance(orders, list):
    orders = []
    failures.append("state orders must be a list")

flows_with_notes: set[str] = set()
notes_by_flow: dict[str, list[str]] = {flow: [] for flow in REQUIRED_FLOWS}
for order in orders:
    if not isinstance(order, dict):
        continue

    flow = str(order.get("flow", ""))
    order_id = order.get("order_id", "")
    notes = [normalize_note(note) for note in order.get("notes", []) if normalize_note(note)]
    if notes:
        flows_with_notes.add(flow)
        if flow in notes_by_flow:
            notes_by_flow[flow].extend(notes)

    seen: dict[str, int] = {}
    for note in notes:
        for sentinel in ENGLISH_SENTINELS:
            if sentinel in note:
                failures.append(
                    f"english sentinel found: order {order_id} flow {flow}: {sentinel}"
                )

        if note in seen:
            failures.append(f"duplicate order note: order {order_id} flow {flow}: {note}")
        else:
            seen[note] = 1

missing_flows = [flow for flow in REQUIRED_FLOWS if flow not in flows_with_notes]
if missing_flows:
    failures.append(f"missing required flow notes: {', '.join(missing_flows)}")

translation_source = str(state.get("translation_source", ""))
if translation_source not in {"catalog", "deterministic_gettext_probe"}:
    failures.append(f"unexpected translation source: {translation_source or 'missing'}")
else:
    for flow in REQUIRED_FLOWS:
        if flow not in flows_with_notes:
            continue
        flow_notes = "\n".join(notes_by_flow[flow])
        if translation_source == "deterministic_gettext_probe":
            marker = FLOW_PROBE_MARKERS[flow]
            if marker not in flow_notes:
                failures.append(f"missing deterministic gettext marker for {flow}: {marker}")
            continue

        if not any(re.search(pattern, flow_notes, flags=re.IGNORECASE) for pattern in FLOW_LOCALIZED_PATTERNS[flow]):
            failures.append(f"missing flow-specific localized note: {flow}")

catalog = state.get("catalog_evidence")
if not isinstance(catalog, dict):
    blockers.append("catalog translation unavailable: missing catalog evidence")
else:
    if catalog.get("schema") != "woopayments_i18n_catalog_evidence.v1":
        blockers.append("catalog translation unavailable: unexpected catalog evidence schema")
    if catalog.get("locale") != expected_locale:
        blockers.append(
            f"catalog translation unavailable: locale {catalog.get('locale')} does not match {expected_locale}"
        )
    if catalog.get("textdomain") != "woocommerce" or catalog.get("textdomain_loaded") is not True:
        blockers.append("catalog translation unavailable: WooCommerce text domain was not loaded")

    catalog_messages = catalog.get("messages")
    if not isinstance(catalog_messages, dict):
        catalog_messages = {}
    for flow, message_id in CATALOG_MESSAGE_IDS.items():
        message = catalog_messages.get(flow)
        if not isinstance(message, dict) or message.get("message_id") != message_id:
            blockers.append(f"catalog translation unavailable: missing exact {flow} message ID")
        elif message.get("translated") is not True:
            blockers.append(f"catalog translation unavailable: {flow} message remains English")

rollup = {
    "schema": "woopayments_i18n_notes_gate_result.v1",
    "status": "fail" if failures else "blocked" if blockers else "pass",
    "implementation_status": "fail" if failures else "pass",
    "catalog_status": "blocked" if blockers else "pass",
    "failures": failures,
    "blockers": blockers,
    "expected_locale": expected_locale,
    "required_flows": REQUIRED_FLOWS,
    "english_sentinels": ENGLISH_SENTINELS,
    "state": state,
}
rollup_path.parent.mkdir(parents=True, exist_ok=True)
rollup_path.write_text(json.dumps(rollup, sort_keys=True, indent=2) + "\n", encoding="utf-8")

if failures:
    for failure in failures:
        print(failure, file=sys.stderr)
    sys.exit(1)

if blockers:
    for blocker in blockers:
        print(blocker, file=sys.stderr)
    sys.exit(3)

print("PASS: native WooPayments order notes are localized and deduplicated.")
PY
}

trap handle_exit EXIT
trap 'handle_signal 129' HUP
trap 'handle_signal 130' INT
trap 'handle_signal 143' TERM

if [ -z "$STATE" ]; then
	if [ ! -x "$FLOW_DRIVE" ]; then
		blocked "flow driver is missing or not executable: $FLOW_DRIVE"
	fi

	assert_target_native_owner
	switch_language
	CATALOG_EVIDENCE="$OUT_DIR/i18n-catalog-evidence.json"
	capture_catalog_evidence "$CATALOG_EVIDENCE"
	install_translation_probe

	charge_flow="$OUT_DIR/charge-flow.json"
	refund_flow="$OUT_DIR/refund-flow.json"
	dispute_flow="$OUT_DIR/dispute-flow.json"
	STATE="$OUT_DIR/i18n-notes-state.json"

	drive_flow "charge" "$charge_flow" charge --deterministic --native --type=success
	charge_order_id="$(json_file_field "$charge_flow" order_id)"
	case "$charge_order_id" in
		''|*[!0-9]*) blocked "charge flow did not emit a valid order id." ;;
	esac

	drive_flow "refund" "$refund_flow" refund --deterministic --order-id "$charge_order_id" --type=partial

	drive_flow "dispute" "$dispute_flow" dispute --deterministic --native --quantity=3
	dispute_order_id="$(json_file_field "$dispute_flow" order_id)"
	case "$dispute_order_id" in
		''|*[!0-9]*) blocked "dispute flow did not emit a valid order id." ;;
	esac

	poll_notes "$STATE" "charge:$charge_order_id" "refund:$charge_order_id" "dispute:$dispute_order_id"
	attach_translation_evidence "$STATE" "$CATALOG_EVIDENCE"
fi

validate_state "$STATE"
