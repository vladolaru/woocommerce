#!/usr/bin/env bash
#
# Harness orchestrator (WooPayments to core merge). ONE entry point that runs the full
# verification loop and reports a per-gate verdict + an aggregate exit code, so an autonomous
# implementor (or CI) can loop on it with no human in the loop.
#
# Two modes:
#   verify.sh --self-check "<WP>"               # A0: prove the harness agrees with reality on the
#                                               #     UNMODIFIED plugin (the trust gate). Default store: reference.
#   verify.sh --ref "<WP>" --target "<WP>"      # A1+: cross-store parity (reference plugin vs target native)
#
# Flags:
#   --with-tracks    also run the sink-based Tracks parity gate around the deterministic charge
#                    flow. Off by default since it clears the shared local wpcom-local Tracks sink.
#   --full-evidence  run the accumulated final evidence plan with nested self-check,
#                    tracks verifier, and broader fixture/browser readiness gates.
#   --print-full-evidence-plan
#                    print the final readiness gate plan and exit without running stores.
#
# Exit: 0 only if every gate PASSED. Any FAIL or BLOCKED -> non-zero (so a CI loop stops).
# PRECONDITIONS (see HARNESS.md): connected test account, event listener running, valid host
# `stripe login` for the financial gate. Local-only; never the remote WPCOM sandbox.

set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SELF_DIR/../.." && pwd)"

MODE=""
REF_WP=""
TARGET_WP=""
WITH_TRACKS=0
FULL_EVIDENCE=0
PRINT_FULL_EVIDENCE_PLAN=0
TRACKS_REF_STORE_ID="${TRACKS_REF_STORE_ID:-}"
TRACKS_TARGET_STORE_ID="${TRACKS_TARGET_STORE_ID:-}"
WLOCAL="${WLOCAL:-wpcom-local}"
TRACKS_OUT_DIR="${TRACKS_OUT_DIR:-${TMPDIR:?TMPDIR is required for Tracks evidence}/woopayments-tracks-parity}"
GATE_LOG_DIR="${GATE_LOG_DIR:-}"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
SUBSCRIPTIONS_REF_SUBSCRIPTION_ID="${SUBSCRIPTIONS_REF_SUBSCRIPTION_ID:-}"
SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID="${SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID:-}"
TOKEN_CONTINUITY_CUSTOMER_ID="${TOKEN_CONTINUITY_CUSTOMER_ID:-}"
TOKEN_CONTINUITY_SUBSCRIPTION_ID="${TOKEN_CONTINUITY_SUBSCRIPTION_ID:-}"
FULL_EVIDENCE_OUT_DIR="${FULL_EVIDENCE_OUT_DIR:-${TMPDIR:-$SELF_DIR/.tmp}/woopayments-final-evidence}"
FINAL_TRACKS_OUT_DIR="${FINAL_TRACKS_OUT_DIR:-}"
CRITICAL_FLOWS_AGENT_RESULTS_DIR="${CRITICAL_FLOWS_AGENT_RESULTS_DIR:-}"
REF_URL="${REF_URL:-http://localhost:8082}"
TARGET_URL="${TARGET_URL:-http://store8889.localhost:8889}"
LPM_FULL_METHODS="sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay"
WCPAY_REPO="${WCPAY_REPO:-$REPO_ROOT/../woocommerce-payments}"

usage() {
	cat >&2 <<'USAGE'
usage: verify.sh (--self-check WP | --ref WP --target WP) [--with-tracks] [--full-evidence] [options]

Options:
  --with-tracks                 Include sink-based Tracks parity for the deterministic charge flow.
  --tracks-ref-store-id ID      Filter reference Tracks capture to this woocommerce_store_id.
  --tracks-target-store-id ID   Filter target Tracks capture to this woocommerce_store_id.
  --full-evidence               Run the accumulated final evidence plan, including
                                nested self-check, Tracks verifier, and readiness gates.
  --print-full-evidence-plan    Print the final readiness gate plan and exit.
  --playwriter-session ID       Playwriter session for browser-dependent final gates.
  --ref-subscription-id ID      Browser-created reference subscription for renewal compare.
  --target-subscription-id ID   Browser-created target subscription for renewal compare.
  --token-customer-id ID        Customer fixture ID for token-continuity evidence.
  --token-subscription-id ID    Subscription fixture ID for token-continuity evidence.
  --full-evidence-out-dir DIR   Evidence output directory for final gates.
  --critical-flows-agent-results-dir DIR
                                Completed Layer-A JSON results for critical flows.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--self-check) MODE="self"; REF_WP="$2"; TARGET_WP="$2"; shift 2 ;;
		--ref) MODE="cross"; REF_WP="$2"; shift 2 ;;
		--target) TARGET_WP="$2"; shift 2 ;;
		--with-tracks) WITH_TRACKS=1; shift ;;
		--tracks-ref-store-id) TRACKS_REF_STORE_ID="${2:-}"; shift 2 ;;
		--tracks-target-store-id) TRACKS_TARGET_STORE_ID="${2:-}"; shift 2 ;;
		--full-evidence) FULL_EVIDENCE=1; shift ;;
		--print-full-evidence-plan) FULL_EVIDENCE=1; PRINT_FULL_EVIDENCE_PLAN=1; shift ;;
		--playwriter-session) PLAYWRITER_SESSION="${2:-}"; shift 2 ;;
		--ref-subscription-id) SUBSCRIPTIONS_REF_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--target-subscription-id) SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--token-customer-id) TOKEN_CONTINUITY_CUSTOMER_ID="${2:-}"; shift 2 ;;
		--token-subscription-id) TOKEN_CONTINUITY_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--full-evidence-out-dir) FULL_EVIDENCE_OUT_DIR="${2:-}"; shift 2 ;;
		--critical-flows-agent-results-dir) CRITICAL_FLOWS_AGENT_RESULTS_DIR="${2:-}"; shift 2 ;;
		*) echo "Unknown arg: $1" >&2; usage; exit 2 ;;
	esac
done
if [ -z "$MODE" ] || [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	usage
	exit 2
fi

CRITICAL_FLOWS_EVIDENCE_DIR="$FULL_EVIDENCE_OUT_DIR/critical-flows"
CRITICAL_FLOWS_AGENT_RESULTS_DIR="${CRITICAL_FLOWS_AGENT_RESULTS_DIR:-$FULL_EVIDENCE_OUT_DIR/critical-flows-agent-results}"
FINAL_TRACKS_OUT_DIR="${FINAL_TRACKS_OUT_DIR:-$FULL_EVIDENCE_OUT_DIR/tracks-parity}"
if [ "$FULL_EVIDENCE" -eq 1 ]; then
	GATE_LOG_DIR="${GATE_LOG_DIR:-$FULL_EVIDENCE_OUT_DIR/logs}"
fi

PASS=(); FAILED=(); BLOCKED=()
record() { # $1 gate, $2 status(PASS|FAIL|BLOCKED)
	case "$2" in
		PASS) PASS+=("$1") ;;
		FAIL) FAILED+=("$1") ;;
		*)    BLOCKED+=("$1") ;;
	esac
	printf '  [%-7s] %s\n' "$2" "$1"
}

gate_log_slug() {
	local slug

	slug="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9._-]+/-/g; s/-+/-/g; s/^-//; s/-$//')"
	if [ -z "$slug" ]; then
		slug="gate"
	fi

	printf '%s' "$slug"
}

# Run a gate command; classify by exit code (0 PASS, 2/3 BLOCKED-preconditions, else FAIL).
gate() { # $1 label ; $2.. command
	local label="$1"; shift
	local rc out_base out_file tmp_base slug
	tmp_base="${GATE_LOG_DIR:-${TMPDIR:-$SELF_DIR/.tmp}}"
	mkdir -p "$tmp_base"
	slug="$(gate_log_slug "$label")"
	out_base="$(mktemp "$tmp_base/woopayments-merge-gate.${slug}.XXXXXX")"
	out_file="${out_base}.log"
	mv "$out_base" "$out_file"
	{
		printf 'GATE: %s\n' "$label"
		printf 'COMMAND:'
		printf ' %q' "$@"
		printf '\n\n'
	} > "$out_file"
	printf '  [RUN    ] %s\n' "$label"
	"$@" 2>&1 | sed 's/^/      /' | tee -a "$out_file"
	rc=${PIPESTATUS[0]}
	if [ "$rc" -eq 0 ]; then record "$label" PASS
	elif [ "$rc" -eq 2 ] || [ "$rc" -eq 3 ]; then
		record "$label" BLOCKED
		printf '      log: %s\n' "$out_file"
	else
		record "$label" FAIL
		printf '      log: %s\n' "$out_file"
	fi
}

TRACKS_REF_FILE="$TRACKS_OUT_DIR/reference-tracks.txt"
TRACKS_TARGET_FILE="$TRACKS_OUT_DIR/target-tracks.txt"
TRACKS_BLOCK_REASON=""
TRACKS_REF_TRACKING_ORIGINAL=""
TRACKS_TARGET_TRACKING_ORIGINAL=""
TRACKS_REF_TRACKING_SNAPSHOTTED=0
TRACKS_TARGET_TRACKING_SNAPSHOTTED=0
TRACKS_LOCAL_SINK_ENDPOINT=""
TRACKS_LOCAL_SINK_TOKEN=""
TRACKS_REF_HELPER_ENABLED_ORIGINAL=""
TRACKS_REF_HELPER_ENDPOINT_ORIGINAL=""
TRACKS_REF_HELPER_TOKEN_ORIGINAL=""
TRACKS_REF_HELPER_CAPTURE_BROWSER_ORIGINAL=""
TRACKS_REF_HELPER_CAPTURE_SERVER_ORIGINAL=""
TRACKS_TARGET_HELPER_ENABLED_ORIGINAL=""
TRACKS_TARGET_HELPER_ENDPOINT_ORIGINAL=""
TRACKS_TARGET_HELPER_TOKEN_ORIGINAL=""
TRACKS_TARGET_HELPER_CAPTURE_BROWSER_ORIGINAL=""
TRACKS_TARGET_HELPER_CAPTURE_SERVER_ORIGINAL=""
TRACKS_REF_HELPER_SNAPSHOTTED=0
TRACKS_TARGET_HELPER_SNAPSHOTTED=0

tracks_block() {
	if [ -z "$TRACKS_BLOCK_REASON" ]; then
		TRACKS_BLOCK_REASON="$1"
	fi
}

tracks_reset() {
	local role="$1" raw rc
	raw="$(bash "$SELF_DIR/tracks-parity.sh" reset 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		tracks_block "$role Tracks sink reset failed: $raw"
		return 1
	fi
	printf '  tracks sink reset before %s capture\n' "$role"
	return 0
}

tracks_get_tracking_option() {
	local role="$1" wp_cmd="$2" raw rc line value

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - <<'PHP' 2>&1
<?php
$value = get_option( 'woocommerce_allow_tracking', null );
echo 'TRACKING:' . ( null === $value ? '__MISSING__' : (string) $value ) . PHP_EOL;
PHP
	)"
	rc=$?
	line="$(printf '%s\n' "$raw" | grep -oE 'TRACKING:.*' | tail -1)"
	value="${line#TRACKING:}"

	if [ "$rc" -ne 0 ] || [ -z "$line" ]; then
		tracks_block "$role usage-tracking snapshot failed: $raw"
		return 1
	fi

	printf '%s\n' "$value"
	return 0
}

tracks_set_tracking_option() {
	local role="$1" wp_cmd="$2" value="$3" raw rc

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - "$value" <<'PHP' 2>&1
<?php
$value = isset( $args[0] ) ? (string) $args[0] : '';
if ( '__MISSING__' === $value ) {
	delete_option( 'woocommerce_allow_tracking' );
} else {
	update_option( 'woocommerce_allow_tracking', $value );
}
echo 'TRACKING_SET:' . $value . PHP_EOL;
PHP
)"
	rc=$?

	if [ "$rc" -ne 0 ]; then
		tracks_block "$role usage-tracking update failed: $raw"
		return 1
	fi

	return 0
}

tracks_get_local_sink_config() {
	local raw endpoint secret_path token

	raw="$($WLOCAL tracks path 2>&1)"
	endpoint="$(printf '%s\n' "$raw" | sed -n 's/.*endpoint_url: //p' | head -1)"
	if [ -z "$endpoint" ]; then
		tracks_block "could not resolve local Tracks sink endpoint from wpcom-local tracks path"
		return 1
	fi

	secret_path="${WPCOM_LOCAL_HOME:-$HOME/.wpcom-local}/secrets/tracks.json"
	if ! command -v python3 >/dev/null 2>&1; then
		tracks_block "python3 is required to read the local Tracks sink token"
		return 1
	fi

	token="$(python3 - "$secret_path" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
try:
    data = json.loads(path.read_text(encoding="utf-8"))
except Exception:
    sys.exit(1)

token = str(data.get("sink_token") or data.get("data", {}).get("sink_token", ""))
if not token:
    sys.exit(1)

print(token)
PY
)"
	if [ -z "$token" ]; then
		tracks_block "could not read local Tracks sink token from ${secret_path}"
		return 1
	fi

	TRACKS_LOCAL_SINK_ENDPOINT="$endpoint"
	TRACKS_LOCAL_SINK_TOKEN="$token"
	return 0
}

tracks_store_helper_original() {
	local role="$1" key="$2" value="$3" prefix="" suffix=""

	case "$role" in
		reference) prefix="TRACKS_REF_HELPER" ;;
		target) prefix="TRACKS_TARGET_HELPER" ;;
		*) return 1 ;;
	esac

	case "$key" in
		enabled) suffix="ENABLED" ;;
		sink_endpoint) suffix="ENDPOINT" ;;
		sink_token) suffix="TOKEN" ;;
		capture_browser) suffix="CAPTURE_BROWSER" ;;
		capture_server) suffix="CAPTURE_SERVER" ;;
		*) return 1 ;;
	esac

	if [ -z "$value" ]; then
		value="__EMPTY_BASE64__"
	fi

	printf -v "${prefix}_${suffix}_ORIGINAL" '%s' "$value"
	return 0
}

tracks_snapshot_helper_config() {
	local role="$1" wp_cmd="$2" raw rc key line value missing=0

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - <<'PHP' 2>&1
<?php
$options = array(
	'enabled'         => 'wpcom_local_helper_tracks_enabled',
	'sink_endpoint'   => 'wpcom_local_helper_tracks_sink_endpoint',
	'sink_token'      => 'wpcom_local_helper_tracks_sink_token',
	'capture_browser' => 'wpcom_local_helper_tracks_capture_browser',
	'capture_server'  => 'wpcom_local_helper_tracks_capture_server',
);

foreach ( $options as $key => $option ) {
	$value = get_option( $option, null );
	echo 'HELPER_OPTION:' . $key . ':' . base64_encode( null === $value ? '__MISSING__' : (string) $value ) . PHP_EOL;
}
PHP
)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		tracks_block "$role local Tracks helper snapshot failed"
		return 1
	fi

	for key in enabled sink_endpoint sink_token capture_browser capture_server; do
		line="$(printf '%s\n' "$raw" | grep -oE "HELPER_OPTION:${key}:.*" | tail -1)"
		if [ -z "$line" ]; then
			missing=1
			continue
		fi

		value="${line#HELPER_OPTION:${key}:}"
		tracks_store_helper_original "$role" "$key" "$value" || missing=1
	done

	if [ "$missing" -ne 0 ]; then
		tracks_block "$role local Tracks helper snapshot was incomplete"
		return 1
	fi

	if [ "$role" = "reference" ]; then
		TRACKS_REF_HELPER_SNAPSHOTTED=1
	else
		TRACKS_TARGET_HELPER_SNAPSHOTTED=1
	fi

	return 0
}

tracks_enable_helper_config() {
	local role="$1" wp_cmd="$2" raw rc

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd wpcom-local tracks enable --endpoint="$TRACKS_LOCAL_SINK_ENDPOINT" --token="$TRACKS_LOCAL_SINK_TOKEN" --capture-browser=yes --capture-server=yes 2>&1)"
	rc=$?

	if [ "$rc" -ne 0 ]; then
		tracks_block "$role local Tracks helper enable failed; run wp wpcom-local tracks status for details"
		return 1
	fi

	printf '  staged %s local Tracks helper capture\n' "$role"
	return 0
}

tracks_prepare_helper_config() {
	local role="$1" wp_cmd="$2"

	tracks_snapshot_helper_config "$role" "$wp_cmd" || return 1
	tracks_enable_helper_config "$role" "$wp_cmd" || return 1
	return 0
}

tracks_restore_helper_config_for_role() {
	local role="$1" wp_cmd="$2" raw rc args

	if [ "$role" = "reference" ]; then
		args=(
			"$TRACKS_REF_HELPER_ENABLED_ORIGINAL"
			"$TRACKS_REF_HELPER_ENDPOINT_ORIGINAL"
			"$TRACKS_REF_HELPER_TOKEN_ORIGINAL"
			"$TRACKS_REF_HELPER_CAPTURE_BROWSER_ORIGINAL"
			"$TRACKS_REF_HELPER_CAPTURE_SERVER_ORIGINAL"
		)
	else
		args=(
			"$TRACKS_TARGET_HELPER_ENABLED_ORIGINAL"
			"$TRACKS_TARGET_HELPER_ENDPOINT_ORIGINAL"
			"$TRACKS_TARGET_HELPER_TOKEN_ORIGINAL"
			"$TRACKS_TARGET_HELPER_CAPTURE_BROWSER_ORIGINAL"
			"$TRACKS_TARGET_HELPER_CAPTURE_SERVER_ORIGINAL"
		)
	fi

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - "${args[@]}" <<'PHP' 2>&1
<?php
$options = array(
	'wpcom_local_helper_tracks_enabled',
	'wpcom_local_helper_tracks_sink_endpoint',
	'wpcom_local_helper_tracks_sink_token',
	'wpcom_local_helper_tracks_capture_browser',
	'wpcom_local_helper_tracks_capture_server',
);

foreach ( $options as $index => $option ) {
	$encoded = isset( $args[ $index ] ) ? (string) $args[ $index ] : '';
	if ( '__EMPTY_BASE64__' === $encoded ) {
		$value = '';
	} else {
		$value = '' === $encoded ? '__MISSING__' : (string) base64_decode( $encoded, true );
	}
	if ( '__MISSING__' === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value );
	}
}
echo 'HELPER_RESTORED' . PHP_EOL;
PHP
)"
	rc=$?

	return "$rc"
}

tracks_prepare_tracking() {
	local role="$1" wp_cmd="$2" original

	original="$(tracks_get_tracking_option "$role" "$wp_cmd")" || return 1

	if [ "$role" = "reference" ]; then
		TRACKS_REF_TRACKING_ORIGINAL="$original"
		TRACKS_REF_TRACKING_SNAPSHOTTED=1
	else
		TRACKS_TARGET_TRACKING_ORIGINAL="$original"
		TRACKS_TARGET_TRACKING_SNAPSHOTTED=1
	fi

	if [ "$original" != "yes" ]; then
		tracks_set_tracking_option "$role" "$wp_cmd" "yes" || return 1
		printf '  staged %s usage tracking for Tracks capture\n' "$role"
	else
		printf '  %s usage tracking already enabled for Tracks capture\n' "$role"
	fi

	return 0
}

tracks_restore_tracking() {
	local had_error=0

	if [ "$TRACKS_REF_HELPER_SNAPSHOTTED" -eq 1 ]; then
		tracks_restore_helper_config_for_role "reference" "$REF_WP" || had_error=1
	fi

	if [ "$TRACKS_TARGET_HELPER_SNAPSHOTTED" -eq 1 ]; then
		tracks_restore_helper_config_for_role "target" "$TARGET_WP" || had_error=1
	fi

	if [ "$TRACKS_REF_TRACKING_SNAPSHOTTED" -eq 1 ]; then
		tracks_set_tracking_option "reference" "$REF_WP" "$TRACKS_REF_TRACKING_ORIGINAL" || had_error=1
	fi

	if [ "$TRACKS_TARGET_TRACKING_SNAPSHOTTED" -eq 1 ]; then
		tracks_set_tracking_option "target" "$TARGET_WP" "$TRACKS_TARGET_TRACKING_ORIGINAL" || had_error=1
	fi

	return "$had_error"
}

tracks_normalize() {
	local role="$1" store_id="$2" out_file="$3" raw rc

	if [ -n "$store_id" ]; then
		raw="$(bash "$SELF_DIR/tracks-parity.sh" normalize --store "$store_id" 2>"$out_file.stderr")"
	else
		raw="$(bash "$SELF_DIR/tracks-parity.sh" normalize 2>"$out_file.stderr")"
	fi

	rc=$?
	if [ "$rc" -ne 0 ]; then
		tracks_block "$role Tracks normalization failed: $(cat "$out_file.stderr" 2>/dev/null)"
		return 1
	fi

	if [ -z "$raw" ]; then
		tracks_block "$role Tracks normalization produced no events; ensure usage tracking, wpcom-local-helper, and the Tracks sink are active"
		return 1
	fi

	printf '%s\n' "$raw" > "$out_file"
	if [ ! -s "$out_file" ]; then
		tracks_block "$role Tracks normalization produced no events; ensure usage tracking, wpcom-local-helper, and the Tracks sink are active"
		return 1
	fi

	printf '  captured %s normalized Tracks event(s) for %s\n' "$(wc -l < "$out_file" | tr -d ' ')" "$role"
	return 0
}

plan_quote() {
	local value="$1"
	value="${value//\\/\\\\}"
	value="${value//\"/\\\"}"
	printf '"%s"' "$value"
}

full_evidence_tracks_store_args_for_plan() {
	if [ -n "$TRACKS_REF_STORE_ID" ]; then
		printf ' --tracks-ref-store-id '
		plan_quote "$TRACKS_REF_STORE_ID"
	fi
	if [ -n "$TRACKS_TARGET_STORE_ID" ]; then
		printf ' --tracks-target-store-id '
		plan_quote "$TRACKS_TARGET_STORE_ID"
	fi
}

print_full_evidence_plan() {
	cat <<PLAN
WooPayments final-evidence plan
mode=$MODE
out_dir=$FULL_EVIDENCE_OUT_DIR

bash $SELF_DIR/verify.sh --self-check "$REF_WP"
TRACKS_OUT_DIR=$(plan_quote "$FINAL_TRACKS_OUT_DIR") bash $SELF_DIR/verify.sh --ref "$REF_WP" --target "$TARGET_WP" --with-tracks$(full_evidence_tracks_store_args_for_plan)
bash $SELF_DIR/rest-route-parity.sh --ref "$REF_WP" --target "$TARGET_WP"
bash $SELF_DIR/hook-shape-parity.sh --ref "$REF_WP" --target "$TARGET_WP"
bash $SELF_DIR/subsystem-disposition-gate.sh
bash $SELF_DIR/i18n-notes-gate.sh --target "$TARGET_WP"
bash $SELF_DIR/subscriptions-renewal-gate.sh compare --ref "$REF_WP" --target "$TARGET_WP" --ref-subscription-id "${SUBSCRIPTIONS_REF_SUBSCRIPTION_ID:-<required>}" --target-subscription-id "${SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/subscriptions-renewal"
bash $SELF_DIR/plugin-active-settings-gate.sh --target "$TARGET_WP" --target-url "$TARGET_URL" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --stage-plugin-active-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings"
bash $SELF_DIR/lpm-checkout-gate.sh --methods $LPM_FULL_METHODS --ref "$REF_WP" --target "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods"
bash $SELF_DIR/mc-rates-gate.sh --ref "$REF_WP" --target "$TARGET_WP" --currency-from USD --currencies-to GBP,EUR --out-dir "$FULL_EVIDENCE_OUT_DIR/mc-rates"
bash $SELF_DIR/token-continuity-gate.sh --target "$TARGET_WP" --customer-id "${TOKEN_CONTINUITY_CUSTOMER_ID:-<required>}" --subscription-id "${TOKEN_CONTINUITY_SUBSCRIPTION_ID:-<required>}" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
python3 $SELF_DIR/a5f-cutover-rehearsal.py --target-wp "$TARGET_WP" --target-url "$TARGET_URL" --store-dir "$REPO_ROOT" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5f-cutover"
python3 $SELF_DIR/a5g-multisite-runtime-gate.py --repo "$REPO_ROOT" --wcpay-repo "$WCPAY_REPO" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5g-multisite-runtime"
bash $SELF_DIR/dispute-e2e-gate.sh --ref "$REF_WP" --target "$TARGET_WP"
bash $SELF_DIR/payout-evidence-gate.sh --wp "$REF_WP" --label reference
bash $SELF_DIR/payout-evidence-gate.sh --wp "$TARGET_WP" --label target --native
bash $SELF_DIR/converted-currency-gate.sh --ref "$REF_WP" --target "$TARGET_WP" --currency GBP
python3 $SELF_DIR/a4aq-accumulated-gate.py --repo "$REPO_ROOT" --plugin-repo "$WCPAY_REPO" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/a4aq-accumulated"
python3 $REPO_ROOT/tools/woopayments-critical-flows/test-inventory.py
EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash $REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh fixture_all ref
EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash $REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh fixture_all target
EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash $REPO_ROOT/tools/woopayments-critical-flows/run.sh --store both --layer all --agent-results-dir "$CRITICAL_FLOWS_AGENT_RESULTS_DIR"
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env
pnpm --filter=@woocommerce/admin-library test:js
pnpm --filter=@woocommerce/admin-library ts:check
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
pnpm --filter=@woocommerce/plugin-woocommerce phpstan
PLAN
}

run_quality_evidence() {
	gate "full WooCommerce PHP suite" pnpm --filter=@woocommerce/plugin-woocommerce test:php:env
	gate "full admin Jest suite" pnpm --filter=@woocommerce/admin-library test:js
	gate "admin TypeScript check" pnpm --filter=@woocommerce/admin-library ts:check
	gate "branch lint" pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
	gate "PHPStan" pnpm --filter=@woocommerce/plugin-woocommerce phpstan
}

run_a4aq_accumulated_evidence() {
	if [ -z "$PLAYWRITER_SESSION" ]; then
		record "A4aq accumulated admin/checkout/perf evidence" BLOCKED
		printf '      pass --playwriter-session to run a4aq-accumulated-gate.py\n'
	elif [ ! -f "$WCPAY_REPO/woocommerce-payments.php" ]; then
		record "A4aq accumulated admin/checkout/perf evidence" BLOCKED
		printf '      set WCPAY_REPO to a local WooPayments plugin checkout before running a4aq-accumulated-gate.py\n'
	else
		gate "A4aq accumulated admin/checkout/perf evidence" python3 "$SELF_DIR/a4aq-accumulated-gate.py" --repo "$REPO_ROOT" --plugin-repo "$WCPAY_REPO" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/a4aq-accumulated"
	fi
}

run_full_evidence_gates() {
	local tracks_store_args=()

	if [ "$MODE" != "cross" ]; then
		record "full-evidence gates require --ref/--target" BLOCKED
		return
	fi

	if [ -n "$TRACKS_REF_STORE_ID" ]; then
		tracks_store_args+=( --tracks-ref-store-id "$TRACKS_REF_STORE_ID" )
	fi
	if [ -n "$TRACKS_TARGET_STORE_ID" ]; then
		tracks_store_args+=( --tracks-target-store-id "$TRACKS_TARGET_STORE_ID" )
	fi

	mkdir -p "$FULL_EVIDENCE_OUT_DIR"
	gate "final evidence self-check verifier" bash "$SELF_DIR/verify.sh" --self-check "$REF_WP"
	gate "final evidence tracks verifier" env TRACKS_OUT_DIR="$FINAL_TRACKS_OUT_DIR" bash "$SELF_DIR/verify.sh" --ref "$REF_WP" --target "$TARGET_WP" --with-tracks "${tracks_store_args[@]}"
	gate "final evidence REST route parity" bash "$SELF_DIR/rest-route-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
	gate "final evidence hook-shape parity" bash "$SELF_DIR/hook-shape-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
	gate "final evidence subsystem disposition" bash "$SELF_DIR/subsystem-disposition-gate.sh"
	gate "final evidence i18n notes" bash "$SELF_DIR/i18n-notes-gate.sh" --target "$TARGET_WP"
	if [ -z "$SUBSCRIPTIONS_REF_SUBSCRIPTION_ID" ] || [ -z "$SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID" ]; then
		record "subscriptions renewal compare" BLOCKED
		printf '      pass --ref-subscription-id and --target-subscription-id to run subscriptions-renewal-gate.sh compare\n'
	else
		gate "subscriptions renewal compare" bash "$SELF_DIR/subscriptions-renewal-gate.sh" compare --ref "$REF_WP" --target "$TARGET_WP" --ref-subscription-id "$SUBSCRIPTIONS_REF_SUBSCRIPTION_ID" --target-subscription-id "$SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID" --out-dir "$FULL_EVIDENCE_OUT_DIR/subscriptions-renewal"
	fi
	gate "plugin-active settings screen" bash "$SELF_DIR/plugin-active-settings-gate.sh" --target "$TARGET_WP" --target-url "$TARGET_URL" --playwriter-session "$PLAYWRITER_SESSION" --stage-plugin-active-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings"
	gate "LPM all-method checkout" bash "$SELF_DIR/lpm-checkout-gate.sh" --methods "$LPM_FULL_METHODS" --ref "$REF_WP" --target "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods"
	gate "multi-currency rates refresh" bash "$SELF_DIR/mc-rates-gate.sh" --ref "$REF_WP" --target "$TARGET_WP" --currency-from USD --currencies-to GBP,EUR --out-dir "$FULL_EVIDENCE_OUT_DIR/mc-rates"

	if [ -z "$TOKEN_CONTINUITY_CUSTOMER_ID" ] || [ -z "$TOKEN_CONTINUITY_SUBSCRIPTION_ID" ]; then
		record "token continuity cutover" BLOCKED
		printf '      pass --token-customer-id and --token-subscription-id to run token-continuity-gate.sh\n'
	else
		gate "token continuity cutover" bash "$SELF_DIR/token-continuity-gate.sh" --target "$TARGET_WP" --customer-id "$TOKEN_CONTINUITY_CUSTOMER_ID" --subscription-id "$TOKEN_CONTINUITY_SUBSCRIPTION_ID" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
	fi

	if [ -z "$PLAYWRITER_SESSION" ]; then
		record "A5f cutover rehearsal" BLOCKED
		printf '      pass --playwriter-session to run a5f-cutover-rehearsal.py\n'
	else
		gate "A5f cutover rehearsal" python3 "$SELF_DIR/a5f-cutover-rehearsal.py" --target-wp "$TARGET_WP" --target-url "$TARGET_URL" --store-dir "$REPO_ROOT" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5f-cutover"
	fi

	if [ ! -f "$WCPAY_REPO/woocommerce-payments.php" ]; then
		record "A5g multisite runtime" BLOCKED
		printf '      set WCPAY_REPO to a local WooPayments plugin checkout before running a5g-multisite-runtime-gate.py\n'
	else
		gate "A5g multisite runtime" python3 "$SELF_DIR/a5g-multisite-runtime-gate.py" --repo "$REPO_ROOT" --wcpay-repo "$WCPAY_REPO" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5g-multisite-runtime"
	fi

	gate "provider-created dispute e2e" bash "$SELF_DIR/dispute-e2e-gate.sh" --ref "$REF_WP" --target "$TARGET_WP"
	gate "payout evidence (reference)" bash "$SELF_DIR/payout-evidence-gate.sh" --wp "$REF_WP" --label reference
	gate "payout evidence (target)" bash "$SELF_DIR/payout-evidence-gate.sh" --wp "$TARGET_WP" --label target --native
	gate "converted-currency charge reconciliation" bash "$SELF_DIR/converted-currency-gate.sh" --ref "$REF_WP" --target "$TARGET_WP" --currency GBP
	run_a4aq_accumulated_evidence
	gate "critical flows inventory" python3 "$REPO_ROOT/tools/woopayments-critical-flows/test-inventory.py"
	gate "critical flows fixtures (reference)" env EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash "$REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh" fixture_all ref
	gate "critical flows fixtures (target)" env EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash "$REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh" fixture_all target
	gate "critical flows full run" env EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash "$REPO_ROOT/tools/woopayments-critical-flows/run.sh" --store both --layer all --agent-results-dir "$CRITICAL_FLOWS_AGENT_RESULTS_DIR"
	run_quality_evidence
}

if [ "$PRINT_FULL_EVIDENCE_PLAN" -eq 1 ]; then
	print_full_evidence_plan
	exit 0
fi

wait_for_financial_metadata() { # $1 WP ; $2.. order ids
	local wp="$1"; shift
	local ids=("$@")
	local deadline state pending order_id

	if [ "${#ids[@]}" -eq 0 ]; then
		return 0
	fi

	printf '  waiting for financial metadata on order(s): %s\n' "${ids[*]}"
	deadline=$((SECONDS + 60))
	while [ "$SECONDS" -lt "$deadline" ]; do
		pending=()
		for order_id in "${ids[@]}"; do
			state="$($wp eval-file - "$order_id" <<'PHP' 2>/dev/null
<?php
$order = wc_get_order( (int) $args[0] );
if ( ! $order ) {
	echo "missing\n";
	return;
}

$payment_transaction_id = (string) $order->get_meta( '_wcpay_payment_transaction_id', true );
$transaction_fee        = (string) $order->get_meta( '_wcpay_transaction_fee', true );
$net                    = (string) $order->get_meta( '_wcpay_net', true );

if ( '' !== $payment_transaction_id && '' !== $transaction_fee && '' !== $net ) {
	echo "ready\n";
	return;
}

echo "pending\n";
PHP
)"
			if [ "$state" != "ready" ]; then
				pending+=("$order_id")
			fi
		done

		if [ "${#pending[@]}" -eq 0 ]; then
			printf '  financial metadata ready for order(s): %s\n' "${ids[*]}"
			return 0
		fi

		sleep 2
	done

	printf '  financial metadata still pending for order(s): %s; running reconciler fail-closed.\n' "${pending[*]}"
	return 0
}

summarize_and_exit() {
	echo
	echo "Summary: ${#PASS[@]} passed, ${#FAILED[@]} failed, ${#BLOCKED[@]} blocked."
	if [ "${#FAILED[@]}" -ne 0 ]; then echo "RESULT: FAIL (regressions present)."; exit 1; fi
	if [ "${#BLOCKED[@]}" -ne 0 ]; then echo "RESULT: INCOMPLETE (preconditions unmet - not a regression)."; exit 3; fi
	if [ "$FULL_EVIDENCE" -eq 1 ]; then
		echo "RESULT: PASS - full-evidence gates passed for the local parity/readiness surfaces."
		echo "  NOTE: this is local enablement evidence. It does not claim production canary error"
		echo "  rates, production WPCOM readiness, live-data safety at production scale, release"
		echo "  sequencing, or the default/mandatory flips."
		exit 0
	fi
	echo "RESULT: PASS - the deterministic gates pass on the captured surfaces (Tier A/B)."
	echo "  NOTE: this is NOT full merge verification. Financial reconciliation now checks the widened"
	echo "  money matrix for the supplied orders, but full refund/dispute/payout/multi-currency coverage"
	echo "  still requires those flows to be driven. Browser checkout (incl. 3DS/SCA), Tracks for each"
	echo "  surviving surface, broad perf, and bundle size need their dedicated gates - see HARNESS.md."
	exit 0
}

echo "WooPayments-merge verification loop - mode: $MODE"
echo

if [ "$FULL_EVIDENCE" -eq 1 ]; then
	run_full_evidence_gates
	summarize_and_exit
fi

TRACKS_CAPTURE_READY=0
if [ "$WITH_TRACKS" -eq 1 ]; then
	if [ "$MODE" != "cross" ]; then
		tracks_block "--with-tracks requires cross-store mode with --ref and --target"
	else
		trap tracks_restore_tracking EXIT
		mkdir -p "$TRACKS_OUT_DIR"
		rm -f "$TRACKS_REF_FILE" "$TRACKS_TARGET_FILE" "$TRACKS_REF_FILE.stderr" "$TRACKS_TARGET_FILE.stderr"
		if tracks_get_local_sink_config && tracks_prepare_helper_config "reference" "$REF_WP" && tracks_prepare_helper_config "target" "$TARGET_WP" && tracks_prepare_tracking "reference" "$REF_WP" && tracks_prepare_tracking "target" "$TARGET_WP" && tracks_reset "reference"; then
			TRACKS_CAPTURE_READY=1
		fi
	fi
fi

# 1. BC + Tracks static drift gate (source-level; independent of stores).
gate "drift gate (BC + tracks)" bash "$SELF_DIR/bc-drift-gate.sh"
gate "subsystem disposition inventory" bash "$SELF_DIR/subsystem-disposition-gate.sh"
gate "hook-shape parity" bash "$SELF_DIR/hook-shape-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
gate "REST route parity" bash "$SELF_DIR/rest-route-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
if [ "$MODE" = "cross" ]; then
	gate "i18n notes" bash "$SELF_DIR/i18n-notes-gate.sh" --target "$TARGET_WP"
fi

# 2. Drive a fixture flow on the reference store and collect order ids.
echo "  driving a charge fixture on the reference store..."
FLOW_ARGS=(charge --count=1 --type=success)
if [ "$MODE" = "cross" ]; then
	FLOW_ARGS=(charge --deterministic --sku=test-lab-beaker-001 --quantity=2 --type=success)
fi
IDS="$(WP="$REF_WP" bash "$SELF_DIR/flow-drive.sh" "${FLOW_ARGS[@]}" 2>/dev/null | sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' | tr '\n' ' ')"
if [ -z "$IDS" ]; then
	record "flow-drive (charge)" FAIL
else
	record "flow-drive (charge) -> orders: $IDS" PASS
fi
if [ "$TRACKS_CAPTURE_READY" -eq 1 ]; then
	tracks_normalize "reference" "$TRACKS_REF_STORE_ID" "$TRACKS_REF_FILE" || TRACKS_CAPTURE_READY=0
fi

TARGET_IDS="$IDS"
if [ "$MODE" = "cross" ]; then
	if [ "$TRACKS_CAPTURE_READY" -eq 1 ]; then
		tracks_reset "target" || TRACKS_CAPTURE_READY=0
	fi
	echo "  driving a charge fixture on the target store..."
	TARGET_IDS="$(WP="$TARGET_WP" bash "$SELF_DIR/flow-drive.sh" "${FLOW_ARGS[@]}" --native 2>/dev/null | sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' | tr '\n' ' ')"
	if [ -z "$TARGET_IDS" ]; then
		record "target flow-drive (charge)" FAIL
	else
		record "target flow-drive (charge) -> orders: $TARGET_IDS" PASS
	fi
	if [ "$TRACKS_CAPTURE_READY" -eq 1 ]; then
		tracks_normalize "target" "$TRACKS_TARGET_STORE_ID" "$TRACKS_TARGET_FILE" || TRACKS_CAPTURE_READY=0
	fi
fi

# 3. Bucket-E parity on the fixture orders.
if [ -n "$IDS" ]; then
	if [ "$MODE" = "self" ]; then
		# shellcheck disable=SC2086
		gate "Bucket-E parity (self-check)" bash "$SELF_DIR/parity-diff.sh" --self-check "$REF_WP" $IDS
	elif [ -n "$TARGET_IDS" ]; then
		# shellcheck disable=SC2086
		gate "Bucket-E parity (cross-store)" bash "$SELF_DIR/parity-diff.sh" --ref "$REF_WP" --target "$TARGET_WP" --target-ids "$TARGET_IDS" $IDS
	fi
fi

# 4. Narrow query-count smoke. Self-check compares the reference to its own baseline.
gate "perf smoke (gateway query-count)" env WP="$TARGET_WP" bash "$SELF_DIR/perf-baseline.sh" check

# 5. Financial reconciliation matrix on the fixture orders (money oracle; self-gates on the Stripe session).
if [ -n "$IDS" ]; then
	if [ "$MODE" = "self" ]; then
		# shellcheck disable=SC2086
		wait_for_financial_metadata "$TARGET_WP" $IDS
		# shellcheck disable=SC2086
		gate "financial reconciliation matrix" env WP="$TARGET_WP" bash "$SELF_DIR/financial-reconcile.sh" $IDS
	else
		# shellcheck disable=SC2086
		wait_for_financial_metadata "$REF_WP" $IDS
		# shellcheck disable=SC2086
		gate "financial reconciliation matrix (reference)" env WP="$REF_WP" bash "$SELF_DIR/financial-reconcile.sh" $IDS
		if [ -n "$TARGET_IDS" ]; then
			# shellcheck disable=SC2086
			wait_for_financial_metadata "$TARGET_WP" $TARGET_IDS
			# shellcheck disable=SC2086
			gate "financial reconciliation matrix (target)" env WP="$TARGET_WP" bash "$SELF_DIR/financial-reconcile.sh" $TARGET_IDS
		fi
	fi
fi

# 6. Tracks-parity (opt-in; clears the shared wpcom-local sink between store captures).
if [ "$WITH_TRACKS" -eq 1 ]; then
	if [ -n "$TRACKS_BLOCK_REASON" ]; then
		record "tracks parity" BLOCKED
		printf '      %s\n' "$TRACKS_BLOCK_REASON"
	elif [ ! -s "$TRACKS_REF_FILE" ] || [ ! -s "$TRACKS_TARGET_FILE" ]; then
		record "tracks parity" BLOCKED
		printf '      missing normalized Tracks captures in %s\n' "$TRACKS_OUT_DIR"
	else
		gate "tracks parity" bash "$SELF_DIR/tracks-parity.sh" diff "$TRACKS_REF_FILE" "$TRACKS_TARGET_FILE"
	fi
fi

summarize_and_exit
