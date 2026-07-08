#!/usr/bin/env bash
#
# Native WooPayments translated order-notes gate.
#
# Snapshot mode validates a captured notes payload. Live mode switches the target
# store to de_DE, drives native charge/refund/dispute flows, captures order notes,
# validates them, and restores the original site language.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FLOW_DRIVE="$SELF_DIR/flow-drive.sh"

TARGET_WP=""
STATE=""
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/i18n-notes-gate"
LOCALE="de_DE"
PRINT_PLAN=0
POLL_TRIES="${I18N_NOTES_POLL_TRIES:-60}"
POLL_SLEEP_SECONDS="${I18N_NOTES_POLL_SLEEP_SECONDS:-1}"
ORIGINAL_WPLANG=""
LANGUAGE_SWITCHED=0
TRANSLATION_PROBE_INSTALLED=0
TRANSLATION_PROBE_PLUGIN="woopayments-i18n-notes-gate-translations.php"

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
	python3 - "$TARGET_WP" "$OUT_DIR" "$LOCALE" "$FLOW_DRIVE" <<'PY'
import json
import sys

target_wp, out_dir, locale, flow_drive = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_i18n_notes_gate_plan.v1",
            "target_wp": target_wp,
            "out_dir": out_dir,
            "locale": locale,
            "flow_driver": flow_drive,
            "required_flows": ["charge", "refund", "dispute"],
            "translation_probe": {
                "plugin": "woopayments-i18n-notes-gate-translations.php",
                "purpose": "installs deterministic WooCommerce gettext replacements for native payment note strings during live probes",
            },
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
            ],
        },
        sort_keys=True,
    )
)
PY
}

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

flows = {
    str(order.get("flow", ""))
    for order in data.get("orders", [])
    if any(str(note).strip() for note in order.get("notes", []))
}
sys.exit(0 if {"charge", "refund", "dispute"} <= flows else 1)
PY
}

restore_language() {
	local restore_locale

	if [ "$TRANSLATION_PROBE_INSTALLED" -eq 1 ]; then
		# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
		# shellcheck disable=SC2086
		$TARGET_WP eval-file - <<PHP >/dev/null 2>&1 || true
<?php
\$plugin = WPMU_PLUGIN_DIR . '/' . '$TRANSLATION_PROBE_PLUGIN';
if ( file_exists( \$plugin ) ) {
	unlink( \$plugin );
}
PHP
	fi

	if [ "$LANGUAGE_SWITCHED" -ne 1 ]; then
		return
	fi

	restore_locale="$ORIGINAL_WPLANG"
	if [ -z "$restore_locale" ]; then
		restore_locale="en_US"
	fi

	printf 'i18n notes gate: restoring site language to %s...\n' "$restore_locale" >&2
	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	$TARGET_WP language core install "$restore_locale" >/dev/null 2>&1 || true
	# shellcheck disable=SC2086
	$TARGET_WP site switch-language "$restore_locale" >/dev/null 2>&1 || true
}

assert_target_native_owner() {
	local raw rc owner

	# shellcheck disable=SC2086
	raw="$($TARGET_WP wc-native-payments status 2>&1)"
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

	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval 'echo "WPLANG:" . (string) get_option( "WPLANG", "" ) . PHP_EOL;' 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not read original site language."
	fi
	ORIGINAL_WPLANG="$(printf '%s\n' "$raw" | grep -oE 'WPLANG:[A-Za-z_]*' | tail -1 | cut -d: -f2-)"

	printf 'i18n notes gate: switching target site language to %s...\n' "$LOCALE" >&2
	# shellcheck disable=SC2086
	raw="$($TARGET_WP language core install "$LOCALE" 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not install language pack $LOCALE."
	fi

	# shellcheck disable=SC2086
	raw="$($TARGET_WP site switch-language "$LOCALE" 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not switch site language to $LOCALE."
	fi

	LANGUAGE_SWITCHED=1
	trap restore_language EXIT
}

install_translation_probe() {
	local raw rc

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval-file - <<PHP 2>&1
<?php
\$plugin = WPMU_PLUGIN_DIR . '/' . '$TRANSLATION_PROBE_PLUGIN';
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
			'<strong>Fee details:</strong>' => '<strong>Gebuehrendetails:</strong>',
			'Fee (%1\$s): %2\$s' => 'Gebuehr (%1\$s): %2\$s',
			'Fee: %1\$s' => 'Gebuehr: %1\$s',
			'Base fee: %1\$s' => 'Grundgebuehr: %1\$s',
			'Currency conversion fee: %1\$s' => 'Waehrungsumrechnungsgebuehr: %1\$s',
			'Net payout: %1\$s' => 'Nettoauszahlung: %1\$s',
			'Refunded order' => 'Rueckerstattete Bestellung',
			'A refund of %1\$s %4\$s using %2\$s (%3\$s).' => 'Eine Rueckerstattung von %1\$s %4\$s mit %2\$s (%3\$s).',
			'A refund of %1\$s %5\$s using %2\$s. Reason: %3\$s. (%4\$s)' => 'Eine Rueckerstattung von %1\$s %5\$s mit %2\$s. Grund: %3\$s. (%4\$s)',
			'A refund of %1\$s was <strong>%2\$s</strong> using %3\$s (<code>%4\$s</code>)%5\$s' => 'Eine Rueckerstattung von %1\$s war <strong>%2\$s</strong> mit %3\$s (<code>%4\$s</code>)%5\$s',
			'was successfully processed' => 'wurde erfolgreich verarbeitet',
			'is pending' => 'ist ausstehend',
			'cancelled' => 'abgebrochen',
			'unsuccessful' => 'nicht erfolgreich',
			'Payment dispute and fees have been deducted from your next payout' => 'Zahlungsdisput und Gebuehren wurden von Ihrer naechsten Auszahlung abgezogen',
			'Payment dispute funds have been reinstated' => 'Zahlungsdisputmittel wurden wiederhergestellt',
			'Payment dispute has been updated' => 'Zahlungsdisput wurde aktualisiert',
			'%1\$s. See <a href="%2\$s">dispute overview</a> for more details.' => '%1\$s. Weitere Details in der <a href="%2\$s">Disputuebersicht</a>.',
			'A payment inquiry has been raised for %1\$s with reason "%2\$s". <a href="%4\$s" target="_blank" rel="noopener noreferrer">Response due by %3\$s</a>.' => 'Eine Zahlungsanfrage ueber %1\$s wurde mit Grund "%2\$s" erstellt. <a href="%4\$s" target="_blank" rel="noopener noreferrer">Antwort faellig bis %3\$s</a>.',
			'Payment has been disputed for %1\$s with reason "%2\$s". <a href="%4\$s" target="_blank" rel="noopener noreferrer">Response due by %3\$s</a>.' => 'Zahlung ueber %1\$s wurde mit Grund "%2\$s" angefochten. <a href="%4\$s" target="_blank" rel="noopener noreferrer">Antwort faellig bis %3\$s</a>.',
			'Payment inquiry has been closed with status %1\$s. See <a href="%2\$s" target="_blank" rel="noopener noreferrer">payment status</a> for more details.' => 'Zahlungsanfrage wurde mit Status %1\$s geschlossen. Weitere Details im <a href="%2\$s" target="_blank" rel="noopener noreferrer">Zahlungsstatus</a>.',
			'Dispute has been closed with status %1\$s. See <a href="%2\$s" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details.' => 'Disput wurde mit Status %1\$s geschlossen. Weitere Details in der <a href="%2\$s" target="_blank" rel="noopener noreferrer">Disputuebersicht</a>.',
		);

		return \$map[ \$text ] ?? \$translation;
	},
	10,
	3
);
MU_PLUGIN;

if ( false === file_put_contents( \$plugin, \$source ) ) {
	WP_CLI::error( 'Could not write i18n notes gate translation probe.' );
}

WP_CLI::line( \$plugin );
PHP
)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf '%s\n' "$raw" | tail -20 >&2
		blocked "could not install i18n translation probe."
	fi

	TRANSLATION_PROBE_INSTALLED=1
}

drive_flow() {
	local flow="$1"
	local out_file="$2"
	shift 2

	local raw rc json

	printf 'i18n notes gate: driving %s flow...\n' "$flow" >&2
	raw="$(WP="$TARGET_WP" bash "$FLOW_DRIVE" "$@" 2>&1)"
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
	raw="$($TARGET_WP eval-file - "$@" <<'PHP' 2>&1
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
]


def normalize_note(note: object) -> str:
    return re.sub(r"\s+", " ", str(note)).strip()


state_path = Path(sys.argv[1])
rollup_path = Path(sys.argv[2])
expected_locale = sys.argv[3]

with state_path.open(encoding="utf-8") as stream:
    state = json.load(stream)

failures: list[str] = []

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
for order in orders:
    if not isinstance(order, dict):
        continue

    flow = str(order.get("flow", ""))
    order_id = order.get("order_id", "")
    notes = [normalize_note(note) for note in order.get("notes", []) if normalize_note(note)]
    if notes:
        flows_with_notes.add(flow)

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

rollup = {
    "schema": "woopayments_i18n_notes_gate_result.v1",
    "status": "fail" if failures else "pass",
    "failures": failures,
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

print("PASS: native WooPayments order notes are localized and deduplicated.")
PY
}

if [ -z "$STATE" ]; then
	if [ ! -x "$FLOW_DRIVE" ]; then
		blocked "flow driver is missing or not executable: $FLOW_DRIVE"
	fi

	assert_target_native_owner
	switch_language
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
fi

validate_state "$STATE"
