#!/usr/bin/env bash
#
# Provider-created dispute e2e gate for the WooPayments -> core merge harness.
#
# Drives deterministic provider-created disputes on the reference plugin store and the
# native target store, waits for WooCommerce order side effects, then reconciles each
# order against Stripe raw source.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FLOW_DRIVE="$SELF_DIR/flow-drive.sh"
RECONCILE="$SELF_DIR/financial-reconcile.sh"
REF_WP=""
TARGET_WP=""
POLL_TRIES="${DISPUTE_E2E_POLL_TRIES:-60}"
POLL_SLEEP_SECONDS="${DISPUTE_E2E_POLL_SLEEP_SECONDS:-1}"

usage() {
	cat >&2 <<'USAGE'
usage:
  dispute-e2e-gate.sh --ref "<ref wp>" --target "<target wp>"

Drives deterministic provider-created disputes on both stores, polls for
dispute side effects on the resulting orders, and then runs financial
reconciliation against Stripe raw source for each order.
USAGE
}

progress() {
	printf 'Dispute E2E: %s\n' "$*" >&2
}

blocked() {
	echo "BLOCKED: $*" >&2
	exit 3
}

fail() {
	echo "FAIL: $*" >&2
	exit 1
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
	esac
done

if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	echo "FAIL: both --ref and --target WP commands are required." >&2
	usage
	exit 2
fi

if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi
if ! command -v stripe >/dev/null 2>&1; then
	blocked "Stripe CLI is required for raw provider-source reconciliation."
fi
if [ ! -x "$FLOW_DRIVE" ]; then
	blocked "flow driver is missing or not executable: $FLOW_DRIVE"
fi
if [ ! -x "$RECONCILE" ]; then
	blocked "financial reconciler is missing or not executable: $RECONCILE"
fi

case "$POLL_TRIES:$POLL_SLEEP_SECONDS" in
	*[!0-9:]*|:*|*:)
		echo "FAIL: DISPUTE_E2E_POLL_TRIES and DISPUTE_E2E_POLL_SLEEP_SECONDS must be non-negative integers." >&2
		exit 2
		;;
esac

TMP_BASE="${TMPDIR:-$SELF_DIR/.tmp}"
mkdir -p "$TMP_BASE" || blocked "could not create temp directory $TMP_BASE"
WORK_DIR="$(mktemp -d "$TMP_BASE/dispute-e2e.XXXXXX")" || blocked "could not create gate work directory"
trap 'rm -rf "$WORK_DIR"' EXIT

probe="$(stripe charges list --limit 1 --color off 2>&1)"
probe_rc=$?
if [ "$probe_rc" -ne 0 ] || printf '%s' "$probe" | grep -qi "expired\|no api key\|stripe login\|Invalid API Key"; then
	echo "BLOCKED: Stripe CLI raw-source read unavailable:" >&2
	printf '%s\n' "$probe" | sed 's/^/  /' | head -5 >&2
	exit 3
fi
progress "Stripe CLI raw-source preflight ok"

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

with open(sys.argv[1]) as handle:
    data = json.load(handle)
value = data.get(sys.argv[2], "")
if isinstance(value, bool):
    print("true" if value else "false")
elif value is not None:
    print(value)
' "$1" "$2"
}

run_flow() {
	local role="$1"
	local wp_cmd="$2"
	local native="$3"
	local out_file="$4"
	local raw rc json
	local args=(dispute --deterministic)

	if [ "$native" = "native" ]; then
		args+=(--native)
	fi

	progress "driving deterministic provider dispute on $role"
	raw="$(WP="$wp_cmd" bash "$FLOW_DRIVE" "${args[@]}" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		echo "BLOCKED ($role): deterministic dispute drive did not complete." >&2
		printf '%s\n' "$raw" | tail -20 >&2
		return 3
	fi

	printf '%s\n' "$json" > "$out_file"
	progress "$role drive output: $json"
	return 0
}

read_order_state() {
	local role="$1"
	local wp_cmd="$2"
	local order_id="$3"
	local out_file="$4"
	local raw rc json

	# Intentionally split the WP runner string, matching the rest of this harness's
	# WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - "$order_id" 2>&1 <<'PHP'
<?php
$order_id = isset( $args[0] ) ? (int) $args[0] : 0;
$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

	if ( ! $order ) {
		WP_CLI::line(
			wp_json_encode(
				array(
					'order_id'                 => $order_id,
					'exists'                   => false,
					'status'                   => '',
					'has_dispute_note'         => false,
					'has_dispute_created_note' => false,
					'has_dispute_update_note'  => false,
					'has_on_hold_transition'   => false,
					'has_dispute_refund'       => false,
					'has_dispute_evidence'     => false,
					'side_effects'             => array(),
					'notes'                    => array(),
				)
			)
		);
	return;
}

$notes                    = array();
$has_dispute_note         = false;
$has_dispute_created_note = false;
$has_dispute_update_note  = false;
$has_on_hold_transition   = false;
foreach (
	wc_get_order_notes(
		array(
			'order_id' => $order->get_id(),
			'limit'    => 20,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		)
	) as $note
) {
	$content = isset( $note->content ) ? trim( (string) $note->content ) : '';
	$content_lower = strtolower( $content );
	if ( false !== stripos( $content, 'dispute' ) || false !== stripos( $content, 'payment inquiry' ) ) {
		$has_dispute_note = true;
	}
	if ( false !== strpos( $content_lower, 'payment has been disputed' ) || false !== strpos( $content_lower, 'payment inquiry has been raised' ) ) {
		$has_dispute_created_note = true;
	}
	if (
		false !== strpos( $content_lower, 'payment dispute and fees have been deducted' )
		|| false !== strpos( $content_lower, 'payment dispute funds have been reinstated' )
		|| false !== strpos( $content_lower, 'payment dispute has been updated' )
		) {
			$has_dispute_update_note = true;
		}
		if (
			false !== strpos( $content_lower, 'order status changed from processing to on hold' )
			|| false !== strpos( $content_lower, 'order status changed from processing to on-hold' )
		) {
			$has_on_hold_transition = true;
		}
		$notes[] = array(
		'content'  => $content,
		'added_by' => isset( $note->added_by ) ? (string) $note->added_by : '',
	);
}

$refunds            = array();
$has_dispute_refund = false;
foreach ( $order->get_refunds() as $refund ) {
	$reason = (string) $refund->get_reason();
	if ( false !== stripos( $reason, 'dispute' ) ) {
		$has_dispute_refund = true;
	}
	$refunds[] = array(
		'amount' => (string) $refund->get_amount(),
		'reason' => $reason,
	);
}

$status       = (string) $order->get_status();
$side_effects = array();
if ( 'on-hold' === $status ) {
	$side_effects[] = 'status:on-hold';
	$has_on_hold_transition = true;
}
if ( $has_on_hold_transition ) {
	$side_effects[] = 'status-transition:on-hold';
}
if ( $has_dispute_note ) {
	$side_effects[] = 'note:dispute';
}
if ( $has_dispute_created_note ) {
	$side_effects[] = 'note:dispute-created';
}
if ( $has_dispute_update_note ) {
	$side_effects[] = 'note:dispute-update';
}
if ( $has_dispute_refund ) {
	$side_effects[] = 'refund:dispute';
}

	WP_CLI::line(
		wp_json_encode(
			array(
				'order_id'                 => (int) $order->get_id(),
				'exists'                   => true,
				'status'                   => $status,
					'has_dispute_note'         => $has_dispute_note,
					'has_dispute_created_note' => $has_dispute_created_note,
					'has_dispute_update_note'  => $has_dispute_update_note,
					'has_on_hold_transition'   => $has_on_hold_transition,
					'has_dispute_refund'       => $has_dispute_refund,
				'has_dispute_evidence'     => $has_dispute_note || $has_dispute_refund,
				'side_effects'             => $side_effects,
				'notes'                    => $notes,
				'refunds'                  => $refunds,
			)
		)
	);
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | json_from_text)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		echo "BLOCKED ($role): could not read order $order_id state." >&2
		printf '%s\n' "$raw" | tail -20 >&2
		return 3
	fi

	printf '%s\n' "$json" > "$out_file"
	return 0
}

poll_dispute_side_effect() {
	local role="$1"
	local wp_cmd="$2"
	local order_id="$3"
	local out_file="$4"
	local attempt status has_note has_created_note has_update_note has_on_hold_transition has_refund has_evidence effects rc

	attempt=1
	while [ "$attempt" -le "$POLL_TRIES" ]; do
		read_order_state "$role" "$wp_cmd" "$order_id" "$out_file"
		rc=$?
		if [ "$rc" -ne 0 ]; then
			return "$rc"
		fi

		status="$(json_file_field "$out_file" status)"
		has_note="$(json_file_field "$out_file" has_dispute_note)"
		has_created_note="$(json_file_field "$out_file" has_dispute_created_note)"
		has_update_note="$(json_file_field "$out_file" has_dispute_update_note)"
		has_on_hold_transition="$(json_file_field "$out_file" has_on_hold_transition)"
		has_refund="$(json_file_field "$out_file" has_dispute_refund)"
		has_evidence="$(json_file_field "$out_file" has_dispute_evidence)"
		effects="$(json_file_field "$out_file" side_effects)"
		progress "$role order $order_id poll $attempt/$POLL_TRIES: status=${status:-<empty>} dispute_note=$has_note created_note=$has_created_note update_note=$has_update_note on_hold_transition=$has_on_hold_transition dispute_refund=$has_refund effects=${effects:-[]}"

		if [ "$has_evidence" = "true" ] && [ "$has_created_note" = "true" ] && [ "$has_update_note" = "true" ] && [ "$has_on_hold_transition" = "true" ]; then
			return 0
		fi

		if [ "$attempt" -lt "$POLL_TRIES" ]; then
			sleep "$POLL_SLEEP_SECONDS"
		fi
		attempt=$((attempt + 1))
	done

	return 1
}

compare_side_effects() {
	python3 - "$1" "$2" <<'PY'
import json
import sys

with open(sys.argv[1]) as handle:
    ref = json.load(handle)
with open(sys.argv[2]) as handle:
    target = json.load(handle)

fields = ("has_dispute_note", "has_dispute_created_note", "has_dispute_update_note", "has_on_hold_transition", "has_dispute_refund")
diffs = []
for field in fields:
    if ref.get(field) != target.get(field):
        diffs.append(f"{field}: reference={ref.get(field)!r} target={target.get(field)!r}")

if not ref.get("has_dispute_evidence") or not target.get("has_dispute_evidence"):
    diffs.append(
        "dispute evidence presence: "
        f"reference={ref.get('side_effects', [])!r} target={target.get('side_effects', [])!r}"
    )

if diffs:
    print("FAIL: dispute side-effect facts differ:")
    for diff in diffs:
        print(f"  - {diff}")
    sys.exit(1)

print(
    "ok: dispute side-effect facts match "
    f"(ref_status={ref.get('status')}, target_status={target.get('status')}, "
    f"note={ref.get('has_dispute_note')}, on_hold_transition={ref.get('has_on_hold_transition')}, "
    f"refund={ref.get('has_dispute_refund')})"
)
PY
}

run_reconcile() {
	local role="$1"
	local wp_cmd="$2"
	local order_id="$3"
	local rc

	progress "running financial reconciliation for $role order $order_id"
	WP="$wp_cmd" bash "$RECONCILE" "$order_id"
	rc=$?
	if [ "$rc" -eq 3 ]; then
		return 3
	fi
	if [ "$rc" -ne 0 ]; then
		return 1
	fi
	return 0
}

ref_flow="$WORK_DIR/ref-flow.json"
target_flow="$WORK_DIR/target-flow.json"
ref_state="$WORK_DIR/ref-state.json"
target_state="$WORK_DIR/target-state.json"

run_flow "reference" "$REF_WP" "plugin" "$ref_flow"
rc=$?
if [ "$rc" -ne 0 ]; then
	exit "$rc"
fi

run_flow "target" "$TARGET_WP" "native" "$target_flow"
rc=$?
if [ "$rc" -ne 0 ]; then
	exit "$rc"
fi

ref_order_id="$(json_file_field "$ref_flow" order_id)"
target_order_id="$(json_file_field "$target_flow" order_id)"

case "$ref_order_id:$target_order_id" in
	*[!0-9:]*|:*|*:)
		blocked "deterministic dispute drive did not emit valid order ids."
		;;
esac

progress "polling reference order $ref_order_id for dispute side effects"
poll_dispute_side_effect "reference" "$REF_WP" "$ref_order_id" "$ref_state"
rc=$?
if [ "$rc" -eq 3 ]; then
	exit 3
fi
if [ "$rc" -ne 0 ]; then
	fail "reference order $ref_order_id did not show dispute side effects before timeout."
fi

progress "polling target order $target_order_id for dispute side effects"
poll_dispute_side_effect "target" "$TARGET_WP" "$target_order_id" "$target_state"
rc=$?
if [ "$rc" -eq 3 ]; then
	exit 3
fi
if [ "$rc" -ne 0 ]; then
	fail "target order $target_order_id did not show dispute side effects before timeout."
fi

if ! compare_side_effects "$ref_state" "$target_state"; then
	exit 1
fi

run_reconcile "reference" "$REF_WP" "$ref_order_id"
rc=$?
if [ "$rc" -eq 3 ]; then
	blocked "reference financial reconciliation could not read required raw source data."
fi
if [ "$rc" -ne 0 ]; then
	fail "reference financial reconciliation failed for order $ref_order_id."
fi

run_reconcile "target" "$TARGET_WP" "$target_order_id"
rc=$?
if [ "$rc" -eq 3 ]; then
	blocked "target financial reconciliation could not read required raw source data."
fi
if [ "$rc" -ne 0 ]; then
	fail "target financial reconciliation failed for order $target_order_id."
fi

echo "PASS: provider-created dispute side effects and financial reconciliation match reference."
exit 0
