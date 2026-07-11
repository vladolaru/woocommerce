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
#   --acknowledge-manual-evidence-limitations
#                    exit 0 when the only blocked full-evidence gates are known
#                    manual-testing/payment-method/provider limitations whose artifacts
#                    prove zero failures.
#
# Exit: 0 only if every gate PASSED, or in full-evidence mode if the only blocked rows are
# explicitly acknowledged manual evidence limitations. Any FAIL or unknown BLOCKED -> non-zero
# (so a CI loop stops).
# PRECONDITIONS (see HARNESS.md): connected test account, event listener running, valid host
# `stripe login` for the financial gate. Local-only; never the remote WPCOM sandbox.

set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SELF_DIR/../.." && pwd)"
if ! cd "$REPO_ROOT"; then
	printf 'FAIL: could not enter WooCommerce repository root: %s\n' "$REPO_ROOT" >&2
	exit 2
fi
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	printf 'FAIL: local runner safety library is missing: %s\n' "$LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"

MODE=""
REF_WP=""
TARGET_WP=""
REF_COMPOSE_PROJECT="${REF_COMPOSE_PROJECT:-}"
TARGET_COMPOSE_PROJECT="${TARGET_COMPOSE_PROJECT:-}"
WITH_TRACKS=0
FULL_EVIDENCE=0
PRINT_FULL_EVIDENCE_PLAN=0
VALIDATE_SCOPE_ONLY=0
ACK_MANUAL_EVIDENCE_LIMITATIONS=0
TRACKS_REF_STORE_ID="${TRACKS_REF_STORE_ID:-}"
TRACKS_TARGET_STORE_ID="${TRACKS_TARGET_STORE_ID:-}"
WLOCAL="${WLOCAL:-wpcom-local}"
TRACKS_OUT_DIR="${TRACKS_OUT_DIR:-${TMPDIR:?TMPDIR is required for Tracks evidence}/woopayments-tracks-parity}"
GATE_LOG_DIR="${GATE_LOG_DIR:-}"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
PLAYWRITER_CHECKOUT_SESSION="${PLAYWRITER_CHECKOUT_SESSION:-}"
BROWSER_RUNNER="${BROWSER_RUNNER:-playwright}"
REF_WP_ADMIN_USER="${REF_WP_ADMIN_USER:-admin}"
REF_WP_ADMIN_PASSWORD="${REF_WP_ADMIN_PASSWORD:-admin}"
TARGET_WP_ADMIN_USER="${TARGET_WP_ADMIN_USER:-admin}"
TARGET_WP_ADMIN_PASSWORD="${TARGET_WP_ADMIN_PASSWORD:-password}"
SUBSCRIPTIONS_REF_SUBSCRIPTION_ID="${SUBSCRIPTIONS_REF_SUBSCRIPTION_ID:-}"
SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID="${SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID:-}"
TOKEN_CONTINUITY_CUSTOMER_ID="${TOKEN_CONTINUITY_CUSTOMER_ID:-}"
TOKEN_CONTINUITY_SUBSCRIPTION_ID="${TOKEN_CONTINUITY_SUBSCRIPTION_ID:-}"
TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID="${TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID:-}"
TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID="${TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID:-}"
TOKEN_CONTINUITY_SOURCE_FLOW="${TOKEN_CONTINUITY_SOURCE_FLOW:-}"
PERF_REF_PROCESS_ORDER_ID="${PERF_REF_PROCESS_ORDER_ID:-}"
PERF_TARGET_PROCESS_ORDER_ID="${PERF_TARGET_PROCESS_ORDER_ID:-}"
PERF_REF_REFUND_ORDER_ID="${PERF_REF_REFUND_ORDER_ID:-}"
PERF_TARGET_REFUND_ORDER_ID="${PERF_TARGET_REFUND_ORDER_ID:-}"
PERF_REF_CAPTURE_ORDER_ID="${PERF_REF_CAPTURE_ORDER_ID:-}"
PERF_TARGET_CAPTURE_ORDER_ID="${PERF_TARGET_CAPTURE_ORDER_ID:-}"
FULL_EVIDENCE_OUT_DIR="${FULL_EVIDENCE_OUT_DIR:-}"
FULL_EVIDENCE_QUALITY_GATE_TIMEOUT_SECONDS="${FULL_EVIDENCE_QUALITY_GATE_TIMEOUT_SECONDS:-1800}"
FULL_EVIDENCE_QUALITY_GATE_CLEANUP_TIMEOUT_SECONDS="${FULL_EVIDENCE_QUALITY_GATE_CLEANUP_TIMEOUT_SECONDS:-30}"
FINAL_TRACKS_OUT_DIR="${FINAL_TRACKS_OUT_DIR:-}"
CRITICAL_FLOWS_AGENT_RESULTS_DIR="${CRITICAL_FLOWS_AGENT_RESULTS_DIR:-}"
CRITICAL_FLOW_AGENT_RESULT_PATHS=()
MS07_REFERENCE_BROWSER="${MS07_REFERENCE_BROWSER:-}"
MS07_REFERENCE_STATE="${MS07_REFERENCE_STATE:-}"
MS07_TARGET_BROWSER="${MS07_TARGET_BROWSER:-}"
MS07_TARGET_STATE="${MS07_TARGET_STATE:-}"
REF_URL="${REF_URL:-http://localhost:8082}"
TARGET_URL="${TARGET_URL:-http://store8889.localhost:8889}"
LPM_FULL_METHODS="sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay"
WCPAY_REPO="${WCPAY_REPO:-$REPO_ROOT/../woocommerce-payments}"
WCPAY_SOURCE_ROOT="${WCPAY_EXTENSION_ROOT:-$WCPAY_REPO}"

usage() {
	cat >&2 <<'USAGE'
usage: verify.sh (--self-check WP | --ref WP --target WP) [--with-tracks] [--full-evidence] [options]

Options:
  --with-tracks                 Include sink-based Tracks parity for the deterministic charge flow.
  --tracks-ref-store-id ID      Filter reference Tracks capture to this woocommerce_store_id.
  --tracks-target-store-id ID   Filter target Tracks capture to this woocommerce_store_id.
  --ref-compose-project NAME    Required exact Compose project for a Docker reference runner.
  --target-compose-project NAME Required exact Compose project for a Docker target runner.
  --full-evidence               Run the accumulated final evidence plan, including
                                nested self-check, Tracks verifier, and readiness gates.
  --print-full-evidence-plan    Print the final readiness gate plan and exit.
  --validate-scope-only        Validate runner projects, runtime owners, and local store URLs,
                                then exit before running or mutating any gate.
  --browser-runner RUNNER       Browser runner for final browser gates: playwriter or playwright.
                                Defaults to BROWSER_RUNNER or playwright.
  --playwriter-session ID       Authenticated Playwriter session for admin-oriented final gates.
  --checkout-playwriter-session ID
                                Unauthenticated Playwriter session for checkout-oriented final gates.
  --ref-subscription-id ID      Browser-created reference subscription for renewal compare.
  --target-subscription-id ID   Browser-created target subscription for renewal compare.
  --token-customer-id ID        Customer fixture ID for token-continuity evidence.
  --token-subscription-id ID    Subscription fixture ID for token-continuity evidence.
  --token-checkout-product-id ID
                                Simple product used to save the source token through plugin checkout.
  --token-renewal-product-id ID Subscription product used to provision the token renewal fixture.
  --token-source-flow FLOW    Source token flow for token-continuity evidence:
                              provider-setup-intent, checkout, or add-payment-method.
                              Defaults to provider-setup-intent unless checkout product
                              input is supplied.
  --perf-ref-process-order-id ID
                                Reference unpaid WooPayments order fixture for perf process_payment.
  --perf-target-process-order-id ID
                                Target unpaid WooPayments order fixture for perf process_payment.
  --perf-ref-refund-order-id ID Reference paid/refundable WooPayments order fixture for perf refund.
  --perf-target-refund-order-id ID
                                Target paid/refundable WooPayments order fixture for perf refund.
  --perf-ref-capture-order-id ID
                                Reference authorized WooPayments order fixture for perf capture.
  --perf-target-capture-order-id ID
                                Target authorized WooPayments order fixture for perf capture.
  --full-evidence-out-dir DIR   Evidence output directory for final gates.
  --acknowledge-manual-evidence-limitations
                                Treat known payment-method/manual-testing limitations as
                                acknowledged if their final-evidence artifacts prove zero
                                failures. Unknown blockers remain blocked.
  --critical-flows-agent-results-dir DIR
                                Generated/completed Layer-A JSON results for critical flows.
  --critical-flow-agent-result PATH
                                Existing Layer-A critical-flow JSON to validate and copy.
                                Repeatable; the result must already carry matching v2 provenance.
  --ms07-reference-browser PATH Reference MS-07 admin browser evidence JSON.
  --ms07-reference-state PATH   Reference MS-07 renewal/state assertion JSON.
  --ms07-target-browser PATH    Target MS-07 admin browser evidence JSON.
  --ms07-target-state PATH      Target MS-07 renewal/state assertion JSON.
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
		--ref-compose-project) REF_COMPOSE_PROJECT="${2:-}"; shift 2 ;;
		--target-compose-project) TARGET_COMPOSE_PROJECT="${2:-}"; shift 2 ;;
		--full-evidence) FULL_EVIDENCE=1; shift ;;
		--print-full-evidence-plan) FULL_EVIDENCE=1; PRINT_FULL_EVIDENCE_PLAN=1; shift ;;
		--validate-scope-only) VALIDATE_SCOPE_ONLY=1; shift ;;
		--acknowledge-manual-evidence-limitations) ACK_MANUAL_EVIDENCE_LIMITATIONS=1; shift ;;
		--browser-runner) BROWSER_RUNNER="${2:-}"; shift 2 ;;
		--playwriter-session) PLAYWRITER_SESSION="${2:-}"; shift 2 ;;
		--checkout-playwriter-session|--guest-playwriter-session) PLAYWRITER_CHECKOUT_SESSION="${2:-}"; shift 2 ;;
		--ref-subscription-id) SUBSCRIPTIONS_REF_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--target-subscription-id) SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--token-customer-id) TOKEN_CONTINUITY_CUSTOMER_ID="${2:-}"; shift 2 ;;
		--token-subscription-id) TOKEN_CONTINUITY_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--token-checkout-product-id) TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID="${2:-}"; shift 2 ;;
		--token-renewal-product-id) TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID="${2:-}"; shift 2 ;;
		--token-source-flow) TOKEN_CONTINUITY_SOURCE_FLOW="${2:-}"; shift 2 ;;
		--perf-ref-process-order-id) PERF_REF_PROCESS_ORDER_ID="${2:-}"; shift 2 ;;
		--perf-target-process-order-id) PERF_TARGET_PROCESS_ORDER_ID="${2:-}"; shift 2 ;;
		--perf-ref-refund-order-id) PERF_REF_REFUND_ORDER_ID="${2:-}"; shift 2 ;;
		--perf-target-refund-order-id) PERF_TARGET_REFUND_ORDER_ID="${2:-}"; shift 2 ;;
		--perf-ref-capture-order-id) PERF_REF_CAPTURE_ORDER_ID="${2:-}"; shift 2 ;;
		--perf-target-capture-order-id) PERF_TARGET_CAPTURE_ORDER_ID="${2:-}"; shift 2 ;;
		--full-evidence-out-dir) FULL_EVIDENCE_OUT_DIR="${2:-}"; shift 2 ;;
		--critical-flows-agent-results-dir) CRITICAL_FLOWS_AGENT_RESULTS_DIR="${2:-}"; shift 2 ;;
		--critical-flow-agent-result) CRITICAL_FLOW_AGENT_RESULT_PATHS+=( "${2:-}" ); shift 2 ;;
		--ms07-reference-browser) MS07_REFERENCE_BROWSER="${2:-}"; shift 2 ;;
		--ms07-reference-state) MS07_REFERENCE_STATE="${2:-}"; shift 2 ;;
		--ms07-target-browser) MS07_TARGET_BROWSER="${2:-}"; shift 2 ;;
		--ms07-target-state) MS07_TARGET_STATE="${2:-}"; shift 2 ;;
		*) echo "Unknown arg: $1" >&2; usage; exit 2 ;;
	esac
done
if [ -z "$FULL_EVIDENCE_OUT_DIR" ]; then
	if [ -z "${TMPDIR:-}" ]; then
		printf 'BLOCKED: TMPDIR is required for default full-evidence output.\n' >&2
		exit 3
	fi
	FULL_EVIDENCE_OUT_DIR="$TMPDIR/woopayments-final-evidence-$(date +%Y%m%d%H%M%S)-$$"
fi
if [ -z "$MODE" ] || [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	usage
	exit 2
fi
if [ "$MODE" = "cross" ] && [ "$REF_WP" = "$TARGET_WP" ]; then
	printf 'FAIL: cross-store verification requires distinct WP runners.\n' >&2
	exit 2
fi
if ! runner_error="$(woopayments_validate_local_wp_runner "$REF_WP")"; then
	printf 'FAIL: unsafe reference WP runner: %s\n' "$runner_error" >&2
	exit 2
fi
if ! runner_error="$(woopayments_validate_local_wp_runner "$TARGET_WP")"; then
	printf 'FAIL: unsafe target WP runner: %s\n' "$runner_error" >&2
	exit 2
fi
if ! REF_URL="$(woopayments_normalize_local_url "$REF_URL")"; then
	printf 'FAIL: REF_URL must be an explicit local HTTP(S) URL.\n' >&2
	exit 2
fi
if ! TARGET_URL="$(woopayments_normalize_local_url "$TARGET_URL")"; then
	printf 'FAIL: TARGET_URL must be an explicit local HTTP(S) URL.\n' >&2
	exit 2
fi
if [ "$MODE" = "cross" ] && [ "$REF_URL" = "$TARGET_URL" ]; then
	printf 'FAIL: REF_URL and TARGET_URL must identify distinct local stores.\n' >&2
	exit 2
fi

validate_execution_scope() {
	local reason details docker_bin container

	if ! reason="$(woopayments_validate_docker_compose_project "$REF_WP" "$REF_COMPOSE_PROJECT" 2>&1)"; then
		printf 'BLOCKED: reference runner project identity failed: %s\n' "${reason:-unknown error}" >&2
		return 1
	fi
	details="$(woopayments_docker_runner_details "$REF_WP")" || return 1
	IFS=$'\t' read -r docker_bin container <<< "$details"
	WOOPAYMENTS_APPROVED_REF_CONTAINER="$container"
	if ! reason="$(woopayments_validate_local_store_identity "$REF_WP" plugin "$REF_URL" 2>&1)"; then
		printf 'BLOCKED: reference store identity failed: %s\n' "${reason:-unknown error}" >&2
		return 1
	fi

	if [ "$MODE" = "cross" ]; then
		if ! reason="$(woopayments_validate_docker_compose_project "$TARGET_WP" "$TARGET_COMPOSE_PROJECT" 2>&1)"; then
			printf 'BLOCKED: target runner project identity failed: %s\n' "${reason:-unknown error}" >&2
			return 1
		fi
		details="$(woopayments_docker_runner_details "$TARGET_WP")" || return 1
		IFS=$'\t' read -r docker_bin container <<< "$details"
		WOOPAYMENTS_APPROVED_TARGET_CONTAINER="$container"
		if ! reason="$(woopayments_validate_local_store_identity "$TARGET_WP" native "$TARGET_URL" 2>&1)"; then
			printf 'BLOCKED: target store identity failed: %s\n' "${reason:-unknown error}" >&2
			return 1
		fi
	fi
	export WOOPAYMENTS_APPROVED_REF_CONTAINER WOOPAYMENTS_APPROVED_TARGET_CONTAINER
}

case "$BROWSER_RUNNER" in
	playwriter|playwright) ;;
	*)
		echo "Unknown --browser-runner: $BROWSER_RUNNER" >&2
		usage
		exit 2
		;;
esac

if [ -z "$TOKEN_CONTINUITY_SOURCE_FLOW" ]; then
	if [ -n "$TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID" ]; then
		TOKEN_CONTINUITY_SOURCE_FLOW="checkout"
	else
		TOKEN_CONTINUITY_SOURCE_FLOW="provider_setup_intent"
	fi
fi
TOKEN_CONTINUITY_SOURCE_FLOW="${TOKEN_CONTINUITY_SOURCE_FLOW//-/_}"
case "$TOKEN_CONTINUITY_SOURCE_FLOW" in
	checkout|add_payment_method|provider_setup_intent) ;;
	*)
		echo "Unknown --token-source-flow: $TOKEN_CONTINUITY_SOURCE_FLOW" >&2
		usage
		exit 2
		;;
esac

CRITICAL_FLOWS_EVIDENCE_DIR="$FULL_EVIDENCE_OUT_DIR/critical-flows"
CRITICAL_FLOWS_AGENT_RESULTS_DIR="${CRITICAL_FLOWS_AGENT_RESULTS_DIR:-$FULL_EVIDENCE_OUT_DIR/critical-flows-agent-results}"
CRITICAL_FLOW_CONTEXT_FILE="$FULL_EVIDENCE_OUT_DIR/critical-flow-context.json"
SC04_EVIDENCE_DIR="$FULL_EVIDENCE_OUT_DIR/sc04-saved-card"
SC04_REFERENCE_BROWSER="$SC04_EVIDENCE_DIR/reference-browser.json"
SC04_REFERENCE_STATE="$SC04_EVIDENCE_DIR/reference-state.json"
SC04_TARGET_BROWSER="$SC04_EVIDENCE_DIR/target-browser.json"
SC04_TARGET_STATE="$SC04_EVIDENCE_DIR/target-state.json"
FINAL_TRACKS_OUT_DIR="${FINAL_TRACKS_OUT_DIR:-$FULL_EVIDENCE_OUT_DIR/tracks-parity}"
if [ "$FULL_EVIDENCE" -eq 1 ]; then
	GATE_LOG_DIR="${GATE_LOG_DIR:-$FULL_EVIDENCE_OUT_DIR/logs}"
fi

PASS=(); FAILED=(); BLOCKED=(); ACKNOWLEDGED=()
LAST_GATE_RC=0
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

run_command_with_timeout() { # $1 timeout seconds ; $2.. command
	local timeout_seconds="$1"; shift

	python3 - "$timeout_seconds" "$@" <<'PY'
import os
import selectors
import signal
import subprocess
import sys
import time

try:
	timeout = float(sys.argv[1])
except ValueError:
	print(f"Invalid gate timeout seconds: {sys.argv[1]}", file=sys.stderr)
	raise SystemExit(2)

command = sys.argv[2:]
if not command:
	print("Missing command for timed gate.", file=sys.stderr)
	raise SystemExit(2)

if timeout <= 0:
	raise SystemExit(subprocess.run(command).returncode)

process = subprocess.Popen(
	command,
	stdout=subprocess.PIPE,
	stderr=subprocess.STDOUT,
	bufsize=0,
	start_new_session=True,
)
assert process.stdout is not None

selector = selectors.DefaultSelector()
selector.register(process.stdout.fileno(), selectors.EVENT_READ)
deadline = time.monotonic() + timeout
last_output_byte = None


def emit_chunk(chunk):
	global last_output_byte

	if not chunk:
		return

	last_output_byte = chunk[-1]
	sys.stdout.buffer.write(chunk)
	sys.stdout.buffer.flush()


def ensure_output_line_boundary():
	global last_output_byte

	if last_output_byte is not None and last_output_byte != ord("\n"):
		sys.stdout.write("\n")
		sys.stdout.flush()
		last_output_byte = ord("\n")


def drain_output(wait_seconds):
	while True:
		events = selector.select(timeout=wait_seconds)
		if not events:
			return

		for key, _ in events:
			chunk = os.read(key.fd, 65536)
			if not chunk:
				try:
					selector.unregister(key.fd)
				except (KeyError, ValueError):
					pass
				return
			emit_chunk(chunk)

		wait_seconds = 0

try:
	while True:
		drain_output(0.1)

		rc = process.poll()
		if rc is not None:
			drain_output(0)
			raise SystemExit(rc)

		if time.monotonic() >= deadline:
			ensure_output_line_boundary()
			print(f"TIMEOUT: command exceeded {timeout:g}s; terminating process group.", flush=True)
			try:
				os.killpg(process.pid, signal.SIGTERM)
			except ProcessLookupError:
				pass
			try:
				process.wait(timeout=5)
			except subprocess.TimeoutExpired:
				print("TIMEOUT: command did not exit after SIGTERM; sending SIGKILL.", flush=True)
				try:
					os.killpg(process.pid, signal.SIGKILL)
				except ProcessLookupError:
					pass
				process.wait(timeout=5)
			drain_output(0)
			raise SystemExit(124)
finally:
	selector.close()
PY
}

# Run a gate command; classify by exit code (0 PASS, 2/3/124 BLOCKED-preconditions/timeouts, else FAIL).
gate() { # $1 label ; $2.. command
	local label="$1"; shift
	local rc out_base out_file tmp_base slug timeout_seconds
	timeout_seconds="${GATE_TIMEOUT_SECONDS:-}"
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
		printf '\n'
		if [ -n "$timeout_seconds" ] && [ "$timeout_seconds" != "0" ]; then
			printf 'TIMEOUT_SECONDS: %s\n' "$timeout_seconds"
		fi
		printf '\n'
	} > "$out_file"
	printf '  [RUN    ] %s\n' "$label"
	if [ -n "$timeout_seconds" ] && [ "$timeout_seconds" != "0" ]; then
		run_command_with_timeout "$timeout_seconds" "$@" 2>&1 | sed 's/^/      /' | tee -a "$out_file"
	else
		"$@" 2>&1 | sed 's/^/      /' | tee -a "$out_file"
	fi
	rc=${PIPESTATUS[0]}
	LAST_GATE_RC="$rc"
	if [ "$rc" -eq 0 ]; then record "$label" PASS
	elif [ "$rc" -eq 2 ] || [ "$rc" -eq 3 ] || [ "$rc" -eq 124 ]; then
		record "$label" BLOCKED
		printf '      log: %s\n' "$out_file"
	else
		record "$label" FAIL
		printf '      log: %s\n' "$out_file"
	fi
	return "$rc"
}

gate_with_admin_credentials() { # $1 label ; $2 user ; $3 password ; $4.. command
	local label="$1" admin_user="$2" admin_password="$3"
	shift 3
	local WP_ADMIN_USER="$admin_user"
	local WP_ADMIN_PASSWORD="$admin_password"
	export WP_ADMIN_USER WP_ADMIN_PASSWORD
	gate "$label" "$@"
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
from __future__ import annotations

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

compose_project_arg_for_plan() {
	local flag="$1"
	local runner="$2"
	local project="$3"
	local details

	if [ -n "$project" ]; then
		printf ' --%s-compose-project ' "$flag"
		plan_quote "$project"
		return
	fi
	details="$(woopayments_docker_runner_details "$runner")" || details=""
	if [ -n "$details" ]; then
		printf ' --%s-compose-project "<required-exact-project>"' "$flag"
	fi
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

full_evidence_token_continuity_source_args_for_plan() {
	printf ' --source-flow %s' "${TOKEN_CONTINUITY_SOURCE_FLOW//_/-}"
	if [ -n "$TOKEN_CONTINUITY_SUBSCRIPTION_ID" ]; then
		printf ' --subscription-id '
		plan_quote "$TOKEN_CONTINUITY_SUBSCRIPTION_ID"
	elif [ "$TOKEN_CONTINUITY_SOURCE_FLOW" = "provider_setup_intent" ]; then
		printf ' --renewal-product-id '
		plan_quote "${TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID:-<required>}"
	else
		printf ' --checkout-product-id '
		plan_quote "${TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID:-<required>}"
		printf ' --renewal-product-id '
		plan_quote "${TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID:-<required>}"
	fi
}

full_evidence_admin_browser_session_args_for_plan() {
	if [ "$BROWSER_RUNNER" != "playwriter" ]; then
		return
	fi

	printf ' --playwriter-session '
	plan_quote "${PLAYWRITER_SESSION:-<required-admin-session>}"
}

full_evidence_checkout_browser_session_args_for_plan() {
	if [ "$BROWSER_RUNNER" != "playwriter" ]; then
		return
	fi

	printf ' --playwriter-session '
	plan_quote "${PLAYWRITER_CHECKOUT_SESSION:-<required-checkout-session>}"
}

perf_fixture_var_name() {
	case "$1:$2" in
		reference:process) printf 'PERF_REF_PROCESS_ORDER_ID' ;;
		reference:refund) printf 'PERF_REF_REFUND_ORDER_ID' ;;
		reference:capture) printf 'PERF_REF_CAPTURE_ORDER_ID' ;;
		target:process) printf 'PERF_TARGET_PROCESS_ORDER_ID' ;;
		target:refund) printf 'PERF_TARGET_REFUND_ORDER_ID' ;;
		target:capture) printf 'PERF_TARGET_CAPTURE_ORDER_ID' ;;
		*) return 1 ;;
	esac
}

build_perf_fixture_args() {
	local role="$1"
	local spec probe option var_name value

	PERF_FIXTURE_ARGS=()
	for spec in process:process-order-id refund:refund-order-id capture:capture-order-id; do
		probe="${spec%%:*}"
		option="${spec#*:}"
		var_name="$(perf_fixture_var_name "$role" "$probe")"
		value="${!var_name}"
		if [ -n "$value" ]; then
			PERF_FIXTURE_ARGS+=( "--$option" "$value" )
		fi
	done
}

perf_fixture_ids_any() {
	[ -n "$PERF_REF_PROCESS_ORDER_ID" ] ||
		[ -n "$PERF_REF_REFUND_ORDER_ID" ] ||
		[ -n "$PERF_REF_CAPTURE_ORDER_ID" ] ||
		[ -n "$PERF_TARGET_PROCESS_ORDER_ID" ] ||
		[ -n "$PERF_TARGET_REFUND_ORDER_ID" ] ||
		[ -n "$PERF_TARGET_CAPTURE_ORDER_ID" ]
}

perf_fixture_ids_complete() {
	[ -n "$PERF_REF_PROCESS_ORDER_ID" ] &&
		[ -n "$PERF_REF_REFUND_ORDER_ID" ] &&
		[ -n "$PERF_REF_CAPTURE_ORDER_ID" ] &&
		[ -n "$PERF_TARGET_PROCESS_ORDER_ID" ] &&
		[ -n "$PERF_TARGET_REFUND_ORDER_ID" ] &&
		[ -n "$PERF_TARGET_CAPTURE_ORDER_ID" ]
}

full_evidence_perf_fixture_args_for_plan() {
	local i

	build_perf_fixture_args "$1"
	if [ "${#PERF_FIXTURE_ARGS[@]}" -eq 0 ] && ! perf_fixture_ids_any; then
		printf ' --process-order-id '
		plan_quote "<generated-$1-process-order-id>"
		printf ' --refund-order-id '
		plan_quote "<generated-$1-refund-order-id>"
		printf ' --capture-order-id '
		plan_quote "<generated-$1-capture-order-id>"
		return
	fi

	i=0
	while [ "$i" -lt "${#PERF_FIXTURE_ARGS[@]}" ]; do
		printf ' %s ' "${PERF_FIXTURE_ARGS[$i]}"
		i=$((i + 1))
		plan_quote "${PERF_FIXTURE_ARGS[$i]}"
		i=$((i + 1))
	done
}

critical_flow_agent_result_copy_args_for_plan() {
	local path

	if [ "${#CRITICAL_FLOW_AGENT_RESULT_PATHS[@]}" -eq 0 ]; then
		return
	fi

	for path in "${CRITICAL_FLOW_AGENT_RESULT_PATHS[@]}"; do
		printf ' --copy-agent-result '
		plan_quote "$path"
	done
}

critical_flow_ms07_args_for_plan() {
	printf ' --require-ms07'
	printf ' --ms07-reference-browser '
	plan_quote "${MS07_REFERENCE_BROWSER:-<required-ms07-reference-browser>}"
	printf ' --ms07-reference-state '
	plan_quote "${MS07_REFERENCE_STATE:-<required-ms07-reference-state>}"
	printf ' --ms07-target-browser '
	plan_quote "${MS07_TARGET_BROWSER:-<required-ms07-target-browser>}"
	printf ' --ms07-target-state '
	plan_quote "${MS07_TARGET_STATE:-<required-ms07-target-state>}"
}

critical_flow_sc04_args_for_plan() {
	printf ' --require-sc04'
	printf ' --sc04-reference-browser '
	plan_quote "$SC04_REFERENCE_BROWSER"
	printf ' --sc04-reference-state '
	plan_quote "$SC04_REFERENCE_STATE"
	printf ' --sc04-target-browser '
	plan_quote "$SC04_TARGET_BROWSER"
	printf ' --sc04-target-state '
	plan_quote "$SC04_TARGET_STATE"
}

build_critical_flow_agent_result_args() {
	local path

	CRITICAL_FLOW_AGENT_RESULT_ARGS=(
		--context-file "$CRITICAL_FLOW_CONTEXT_FILE"
		--out-dir "$CRITICAL_FLOWS_AGENT_RESULTS_DIR"
	)
	if [ "${#CRITICAL_FLOW_AGENT_RESULT_PATHS[@]}" -gt 0 ]; then
		for path in "${CRITICAL_FLOW_AGENT_RESULT_PATHS[@]}"; do
			CRITICAL_FLOW_AGENT_RESULT_ARGS+=( --copy-agent-result "$path" )
		done
	fi
	CRITICAL_FLOW_AGENT_RESULT_ARGS+=(
		--require-sc04
		--require-ms07
	)
	CRITICAL_FLOW_AGENT_RESULT_ARGS+=(
		--sc04-reference-browser "$SC04_REFERENCE_BROWSER"
		--sc04-reference-state "$SC04_REFERENCE_STATE"
		--sc04-target-browser "$SC04_TARGET_BROWSER"
		--sc04-target-state "$SC04_TARGET_STATE"
	)
	if [ -n "$MS07_REFERENCE_BROWSER" ]; then
		CRITICAL_FLOW_AGENT_RESULT_ARGS+=( --ms07-reference-browser "$MS07_REFERENCE_BROWSER" )
	fi
	if [ -n "$MS07_REFERENCE_STATE" ]; then
		CRITICAL_FLOW_AGENT_RESULT_ARGS+=( --ms07-reference-state "$MS07_REFERENCE_STATE" )
	fi
	if [ -n "$MS07_TARGET_BROWSER" ]; then
		CRITICAL_FLOW_AGENT_RESULT_ARGS+=( --ms07-target-browser "$MS07_TARGET_BROWSER" )
	fi
	if [ -n "$MS07_TARGET_STATE" ]; then
		CRITICAL_FLOW_AGENT_RESULT_ARGS+=( --ms07-target-state "$MS07_TARGET_STATE" )
	fi
	CRITICAL_FLOW_AGENT_RESULT_ARGS+=(
		--plugin-active-reference-gate "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings-reference/plugin-active-settings-gate.json"
		--plugin-active-target-gate "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings/plugin-active-settings-gate.json"
		--lpm-gate "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods/lpm-checkout-gate.json"
		--token-continuity-gate "$FULL_EVIDENCE_OUT_DIR/token-continuity/token-continuity-gate.json"
	)
}

print_full_evidence_plan() {
	cat <<PLAN
WooPayments final-evidence plan
mode=$MODE
out_dir=$FULL_EVIDENCE_OUT_DIR

bash $SELF_DIR/verify.sh --self-check "$REF_WP"$(compose_project_arg_for_plan ref "$REF_WP" "$REF_COMPOSE_PROJECT")
TRACKS_OUT_DIR=$(plan_quote "$FINAL_TRACKS_OUT_DIR") bash $SELF_DIR/verify.sh --ref "$REF_WP" --target "$TARGET_WP"$(compose_project_arg_for_plan ref "$REF_WP" "$REF_COMPOSE_PROJECT")$(compose_project_arg_for_plan target "$TARGET_WP" "$TARGET_COMPOSE_PROJECT") --with-tracks$(full_evidence_tracks_store_args_for_plan)
bash $SELF_DIR/rest-route-parity.sh --ref "$REF_WP" --target "$TARGET_WP"
bash $SELF_DIR/hook-shape-parity.sh --ref "$REF_WP" --target "$TARGET_WP"
bash $SELF_DIR/subsystem-disposition-gate.sh
bash $SELF_DIR/i18n-notes-gate.sh --target "$TARGET_WP"
bash $SELF_DIR/money-path-parity-gate.sh --ref "$REF_WP" --target "$TARGET_WP" --out-dir "$FULL_EVIDENCE_OUT_DIR/money-path-parity"
bash $SELF_DIR/subscriptions-renewal-gate.sh compare --ref "$REF_WP" --target "$TARGET_WP" --ref-subscription-id "${SUBSCRIPTIONS_REF_SUBSCRIPTION_ID:-<required>}" --target-subscription-id "${SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/subscriptions-renewal"
bash $SELF_DIR/plugin-active-settings-gate.sh --target "$REF_WP" --target-url "$REF_URL" --browser-runner "$BROWSER_RUNNER"$(full_evidence_admin_browser_session_args_for_plan) --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings-reference"
bash $SELF_DIR/plugin-active-settings-gate.sh --target "$TARGET_WP" --target-url "$TARGET_URL" --browser-runner "$BROWSER_RUNNER"$(full_evidence_admin_browser_session_args_for_plan) --stage-plugin-active-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings"
bash $SELF_DIR/lpm-checkout-gate.sh --methods $LPM_FULL_METHODS --ref "$REF_WP" --target "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --browser-runner "$BROWSER_RUNNER"$(full_evidence_checkout_browser_session_args_for_plan) --out-dir "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods"
bash $SELF_DIR/mc-rates-gate.sh --ref "$REF_WP" --target "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --currency-from USD --currencies-to GBP,EUR --out-dir "$FULL_EVIDENCE_OUT_DIR/mc-rates"
bash $SELF_DIR/token-continuity-gate.sh --target "$TARGET_WP" --customer-id "${TOKEN_CONTINUITY_CUSTOMER_ID:-<required>}"$(full_evidence_token_continuity_source_args_for_plan) --browser-runner "$BROWSER_RUNNER"$(full_evidence_checkout_browser_session_args_for_plan) --stage-sepa-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
python3 $SELF_DIR/a5f-cutover-rehearsal.py --target-wp "$TARGET_WP" --target-url "$TARGET_URL" --store-dir "$REPO_ROOT" --browser-runner "$BROWSER_RUNNER"$(full_evidence_admin_browser_session_args_for_plan) --out-dir "$FULL_EVIDENCE_OUT_DIR/a5f-cutover"
python3 $SELF_DIR/a5g-multisite-runtime-gate.py --repo "$REPO_ROOT" --wcpay-repo "$WCPAY_REPO" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5g-multisite-runtime"
bash $SELF_DIR/dispute-e2e-gate.sh --ref "$REF_WP" --target "$TARGET_WP"
bash $SELF_DIR/payout-evidence-gate.sh --wp "$REF_WP" --label reference
bash $SELF_DIR/payout-evidence-gate.sh --wp "$TARGET_WP" --label target --native
bash $SELF_DIR/converted-currency-gate.sh --ref "$REF_WP" --target "$TARGET_WP" --currency GBP
bash $SELF_DIR/bundle-size-gate.sh capture --repo "$WCPAY_REPO" --profile wcpay-plugin --out "$FULL_EVIDENCE_OUT_DIR/bundle-reference.json"
bash $SELF_DIR/bundle-size-gate.sh capture --repo "$REPO_ROOT" --profile wc-core --out "$FULL_EVIDENCE_OUT_DIR/bundle-target.json"
bash $SELF_DIR/bundle-size-gate.sh compare --ref "$FULL_EVIDENCE_OUT_DIR/bundle-reference.json" --target "$FULL_EVIDENCE_OUT_DIR/bundle-target.json" --budget "$SELF_DIR/a4aq-bundle-budget.json"
$(if ! perf_fixture_ids_complete; then printf 'python3 %s/perf-fixtures-gate.py --repo "%s" --ref-wp "%s" --target-wp "%s" --out "%s/perf-fixtures.json"\n' "$SELF_DIR" "$REPO_ROOT" "$REF_WP" "$TARGET_WP" "$FULL_EVIDENCE_OUT_DIR"; fi)
bash $SELF_DIR/perf-surface-gate.sh capture --wp "$REF_WP" --out "$FULL_EVIDENCE_OUT_DIR/perf-reference.json"$(full_evidence_perf_fixture_args_for_plan reference)
bash $SELF_DIR/perf-surface-gate.sh capture --wp "$TARGET_WP" --out "$FULL_EVIDENCE_OUT_DIR/perf-target.json"$(full_evidence_perf_fixture_args_for_plan target)
bash $SELF_DIR/perf-surface-gate.sh compare --ref "$FULL_EVIDENCE_OUT_DIR/perf-reference.json" --target "$FULL_EVIDENCE_OUT_DIR/perf-target.json"
python3 $SELF_DIR/a4aq-accumulated-gate.py --repo "$REPO_ROOT" --plugin-repo "$WCPAY_REPO" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --browser-runner "$BROWSER_RUNNER"$(full_evidence_admin_browser_session_args_for_plan) --out-dir "$FULL_EVIDENCE_OUT_DIR/a4aq-accumulated"
python3 $REPO_ROOT/tools/woopayments-critical-flows/test-inventory.py
EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash $REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh fixture_all ref
EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash $REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh fixture_all target
python3 $REPO_ROOT/tools/woopayments-critical-flows/evidence_context.py create --repo "$REPO_ROOT" --out "$CRITICAL_FLOW_CONTEXT_FILE" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --ref-subscription-id "${SUBSCRIPTIONS_REF_SUBSCRIPTION_ID:-<required-ref-subscription-id>}" --target-subscription-id "${SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID:-<required-target-subscription-id>}"
python3 $SELF_DIR/sc04-saved-card-gate.py --repo "$REPO_ROOT" --context-file "$CRITICAL_FLOW_CONTEXT_FILE" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --ref-subscription-id "${SUBSCRIPTIONS_REF_SUBSCRIPTION_ID:-<required-ref-subscription-id>}" --target-subscription-id "${SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID:-<required-target-subscription-id>}" --browser-runner "$BROWSER_RUNNER" --out-dir "$SC04_EVIDENCE_DIR"
python3 $REPO_ROOT/tools/woopayments-critical-flows/build-agent-results.py --context-file "$CRITICAL_FLOW_CONTEXT_FILE" --out-dir "$CRITICAL_FLOWS_AGENT_RESULTS_DIR"$(critical_flow_agent_result_copy_args_for_plan)$(critical_flow_sc04_args_for_plan)$(critical_flow_ms07_args_for_plan) --plugin-active-reference-gate "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings-reference/plugin-active-settings-gate.json" --plugin-active-target-gate "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings/plugin-active-settings-gate.json" --lpm-gate "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods/lpm-checkout-gate.json" --token-continuity-gate "$FULL_EVIDENCE_OUT_DIR/token-continuity/token-continuity-gate.json"
EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash $REPO_ROOT/tools/woopayments-critical-flows/run.sh --store both --layer all --ref-url "$REF_URL" --target-url "$TARGET_URL" --agent-results-dir "$CRITICAL_FLOWS_AGENT_RESULTS_DIR" --context-file "$CRITICAL_FLOW_CONTEXT_FILE"
pnpm --filter=@woocommerce/plugin-woocommerce wp-env run tests-cli -- sh -lc '! pgrep -f "vendor/bin/phpuni[t]" >/dev/null'
pnpm --filter=@woocommerce/plugin-woocommerce wp-env run tests-cli -- wp db query 'DELETE FROM wp_actionscheduler_logs; DELETE FROM wp_actionscheduler_actions; DELETE FROM wp_actionscheduler_claims;' --allow-root
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments
pnpm --filter=@woocommerce/admin-library test:js
pnpm --filter=@woocommerce/admin-library ts:check
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
pnpm --filter=@woocommerce/plugin-woocommerce phpstan
PLAN
}

run_quality_evidence() {
	if phpunit_tests_are_running; then
		record "WooPayments PHP suite" BLOCKED
		printf '      another PHPUnit process is already running in the shared wp-env tests container; wait for it to finish and rerun this gate\n'
	elif cleanup_phpunit_action_scheduler_state; then
		run_quality_gate "WooPayments PHP suite" pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments
		if [ "${LAST_GATE_RC:-0}" -eq 124 ]; then
			cleanup_timed_out_phpunit
		fi
	else
		record "WooPayments PHP suite preflight cleanup" BLOCKED
		printf '      could not clear stale Action Scheduler rows from the local wp-env tests DB\n'
	fi
	run_quality_gate "full admin Jest suite" pnpm --filter=@woocommerce/admin-library test:js
	run_quality_gate "admin TypeScript check" pnpm --filter=@woocommerce/admin-library ts:check
	run_quality_gate "branch lint" pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
	run_quality_gate "PHPStan" pnpm --filter=@woocommerce/plugin-woocommerce phpstan
}

run_quality_gate() {
	local label="$1"; shift
	GATE_TIMEOUT_SECONDS="$FULL_EVIDENCE_QUALITY_GATE_TIMEOUT_SECONDS" gate "$label" "$@"
}

phpunit_tests_are_running() {
	run_command_with_timeout "$FULL_EVIDENCE_QUALITY_GATE_CLEANUP_TIMEOUT_SECONDS" pnpm --filter=@woocommerce/plugin-woocommerce wp-env run tests-cli -- sh -lc 'pgrep -f "vendor/bin/phpuni[t]" >/dev/null' >/dev/null 2>&1
}

cleanup_phpunit_action_scheduler_state() {
	printf '      cleanup: clearing Action Scheduler rows from the idle wp-env tests DB\n'
	run_command_with_timeout "$FULL_EVIDENCE_QUALITY_GATE_CLEANUP_TIMEOUT_SECONDS" pnpm --filter=@woocommerce/plugin-woocommerce wp-env run tests-cli -- wp db query 'DELETE FROM wp_actionscheduler_logs; DELETE FROM wp_actionscheduler_actions; DELETE FROM wp_actionscheduler_claims;' --allow-root >/dev/null 2>&1
}

cleanup_timed_out_phpunit() {
	printf '      cleanup: stopping any timed-out PHPUnit process in wp-env tests-cli\n'
	run_command_with_timeout "$FULL_EVIDENCE_QUALITY_GATE_CLEANUP_TIMEOUT_SECONDS" pnpm --filter=@woocommerce/plugin-woocommerce wp-env run tests-cli -- sh -lc 'pkill -f "vendor/bin/phpuni[t]" || true' >/dev/null 2>&1 || true
}

run_a4aq_accumulated_evidence() {
	if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_SESSION" ]; then
		record_playwriter_block "A4aq accumulated admin/checkout/perf evidence" "a4aq-accumulated-gate.py" "--playwriter-session"
	elif [ ! -f "$WCPAY_REPO/woocommerce-payments.php" ]; then
		record "A4aq accumulated admin/checkout/perf evidence" BLOCKED
		printf '      set WCPAY_REPO to a local WooPayments plugin checkout before running a4aq-accumulated-gate.py\n'
	else
		if [ "$BROWSER_RUNNER" = "playwriter" ]; then
			gate "A4aq accumulated admin/checkout/perf evidence" python3 "$SELF_DIR/a4aq-accumulated-gate.py" --repo "$REPO_ROOT" --plugin-repo "$WCPAY_REPO" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/a4aq-accumulated"
		else
			gate "A4aq accumulated admin/checkout/perf evidence" python3 "$SELF_DIR/a4aq-accumulated-gate.py" --repo "$REPO_ROOT" --plugin-repo "$WCPAY_REPO" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --browser-runner "$BROWSER_RUNNER" --out-dir "$FULL_EVIDENCE_OUT_DIR/a4aq-accumulated"
		fi
	fi
}

record_playwriter_block() {
	record "$1" BLOCKED
	printf '      pass %s to run %s\n' "$3" "$2"
}

run_bundle_size_evidence() {
	local ref_bundle="$FULL_EVIDENCE_OUT_DIR/bundle-reference.json"
	local target_bundle="$FULL_EVIDENCE_OUT_DIR/bundle-target.json"
	local budget="$SELF_DIR/a4aq-bundle-budget.json"

	if [ ! -f "$WCPAY_REPO/woocommerce-payments.php" ]; then
		record "bundle size evidence" BLOCKED
		printf '      set WCPAY_REPO to a local WooPayments plugin checkout before running bundle-size-gate.sh\n'
		return
	fi

	gate "bundle size capture (reference)" bash "$SELF_DIR/bundle-size-gate.sh" capture --repo "$WCPAY_REPO" --profile wcpay-plugin --out "$ref_bundle"
	gate "bundle size capture (target)" bash "$SELF_DIR/bundle-size-gate.sh" capture --repo "$REPO_ROOT" --profile wc-core --out "$target_bundle"
	gate "bundle size compare" bash "$SELF_DIR/bundle-size-gate.sh" compare --ref "$ref_bundle" --target "$target_bundle" --budget "$budget"
}

load_perf_fixture_ids() {
	local fixtures_file="$1"
	local values old_ifs

	values="$(
		python3 - "$fixtures_file" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
if data.get("status") != "pass":
    raise SystemExit(f"perf fixture status is {data.get('status')!r}")
stores = data.get("stores") or {}
keys = (
    ("reference", "process_payment"),
    ("reference", "refund"),
    ("reference", "capture"),
    ("target", "process_payment"),
    ("target", "refund"),
    ("target", "capture"),
)
values = []
for role, key in keys:
    value = ((stores.get(role) or {}).get("order_ids") or {}).get(key)
    if not value:
        raise SystemExit(f"missing {role} {key} perf fixture order id")
    values.append(str(value))
print("\t".join(values))
PY
	)" || return 1

	old_ifs="$IFS"
	IFS=$'\t'
	read -r PERF_REF_PROCESS_ORDER_ID PERF_REF_REFUND_ORDER_ID PERF_REF_CAPTURE_ORDER_ID PERF_TARGET_PROCESS_ORDER_ID PERF_TARGET_REFUND_ORDER_ID PERF_TARGET_CAPTURE_ORDER_ID <<EOF
$values
EOF
	IFS="$old_ifs"
}

ensure_perf_fixture_ids() {
	local fixtures_file="$FULL_EVIDENCE_OUT_DIR/perf-fixtures.json"

	if perf_fixture_ids_complete; then
		return 0
	fi
	if perf_fixture_ids_any; then
		record "perf fixture inputs" BLOCKED
		printf '      pass all six perf fixture IDs, or pass none and let perf-fixtures-gate.py stage them\n'
		return 1
	fi

	if ! gate "perf fixture staging" python3 "$SELF_DIR/perf-fixtures-gate.py" --repo "$REPO_ROOT" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --out "$fixtures_file"; then
		return 1
	fi
	if ! load_perf_fixture_ids "$fixtures_file"; then
		record "perf fixture loading" BLOCKED
		printf '      perf fixture staging did not write usable order IDs to %s\n' "$fixtures_file"
		return 1
	fi
	return 0
}

run_perf_surface_evidence() {
	local ref_perf="$FULL_EVIDENCE_OUT_DIR/perf-reference.json"
	local target_perf="$FULL_EVIDENCE_OUT_DIR/perf-target.json"
	local ref_capture_ok=0
	local target_capture_ok=0

	if ! ensure_perf_fixture_ids; then
		return
	fi

	build_perf_fixture_args reference
	if [ "${#PERF_FIXTURE_ARGS[@]}" -eq 0 ]; then
		if gate "perf surface capture (reference)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$REF_WP" --out "$ref_perf" && [ -s "$ref_perf" ]; then
			ref_capture_ok=1
		fi
	else
		if gate "perf surface capture (reference)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$REF_WP" --out "$ref_perf" "${PERF_FIXTURE_ARGS[@]}" && [ -s "$ref_perf" ]; then
			ref_capture_ok=1
		fi
	fi

	build_perf_fixture_args target
	if [ "${#PERF_FIXTURE_ARGS[@]}" -eq 0 ]; then
		if gate "perf surface capture (target)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$TARGET_WP" --out "$target_perf" && [ -s "$target_perf" ]; then
			target_capture_ok=1
		fi
	else
		if gate "perf surface capture (target)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$TARGET_WP" --out "$target_perf" "${PERF_FIXTURE_ARGS[@]}" && [ -s "$target_perf" ]; then
			target_capture_ok=1
		fi
	fi
	if [ "$ref_capture_ok" -eq 1 ] && [ "$target_capture_ok" -eq 1 ] && [ -s "$ref_perf" ] && [ -s "$target_perf" ]; then
		gate "perf surface compare" bash "$SELF_DIR/perf-surface-gate.sh" compare --ref "$ref_perf" --target "$target_perf"
	else
		record "perf surface compare" BLOCKED
		printf '      performance comparison requires two successful nonempty captures\n'
	fi
}

run_full_evidence_gates() {
	local tracks_store_args=()
	local self_scope_args=( --self-check "$REF_WP" )
	local cross_scope_args=( --ref "$REF_WP" --target "$TARGET_WP" )

	if [ "$MODE" != "cross" ]; then
		record "full-evidence gates require --ref/--target" BLOCKED
		return
	fi
	if [ -e "$FULL_EVIDENCE_OUT_DIR" ] && { [ ! -d "$FULL_EVIDENCE_OUT_DIR" ] || [ -n "$(find "$FULL_EVIDENCE_OUT_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; }; then
		record "full-evidence output directory" BLOCKED
		printf '      full-evidence output directory is not empty; use a fresh directory so stale artifacts cannot enter this run: %s\n' "$FULL_EVIDENCE_OUT_DIR"
		return
	fi

	if [ -n "$TRACKS_REF_STORE_ID" ]; then
		tracks_store_args+=( --tracks-ref-store-id "$TRACKS_REF_STORE_ID" )
	fi
	if [ -n "$TRACKS_TARGET_STORE_ID" ]; then
		tracks_store_args+=( --tracks-target-store-id "$TRACKS_TARGET_STORE_ID" )
	fi
	if [ -n "$REF_COMPOSE_PROJECT" ]; then
		self_scope_args+=( --ref-compose-project "$REF_COMPOSE_PROJECT" )
		cross_scope_args+=( --ref-compose-project "$REF_COMPOSE_PROJECT" )
	fi
	if [ -n "$TARGET_COMPOSE_PROJECT" ]; then
		cross_scope_args+=( --target-compose-project "$TARGET_COMPOSE_PROJECT" )
	fi

	mkdir -p "$FULL_EVIDENCE_OUT_DIR"
	gate "final evidence self-check verifier" bash "$SELF_DIR/verify.sh" "${self_scope_args[@]}"
	if [ "${#tracks_store_args[@]}" -eq 0 ]; then
		gate "final evidence tracks verifier" env TRACKS_OUT_DIR="$FINAL_TRACKS_OUT_DIR" bash "$SELF_DIR/verify.sh" "${cross_scope_args[@]}" --with-tracks
	else
		gate "final evidence tracks verifier" env TRACKS_OUT_DIR="$FINAL_TRACKS_OUT_DIR" bash "$SELF_DIR/verify.sh" "${cross_scope_args[@]}" --with-tracks "${tracks_store_args[@]}"
	fi
	gate "final evidence REST route parity" bash "$SELF_DIR/rest-route-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
	gate "final evidence hook-shape parity" bash "$SELF_DIR/hook-shape-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
	gate "final evidence subsystem disposition" bash "$SELF_DIR/subsystem-disposition-gate.sh"
	gate "final evidence i18n notes" bash "$SELF_DIR/i18n-notes-gate.sh" --target "$TARGET_WP"
	gate "refund/manual-capture Bucket-E parity" bash "$SELF_DIR/money-path-parity-gate.sh" --ref "$REF_WP" --target "$TARGET_WP" --out-dir "$FULL_EVIDENCE_OUT_DIR/money-path-parity"
	if [ -z "$SUBSCRIPTIONS_REF_SUBSCRIPTION_ID" ] || [ -z "$SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID" ]; then
		record "subscriptions renewal compare" BLOCKED
		printf '      pass --ref-subscription-id and --target-subscription-id to run subscriptions-renewal-gate.sh compare\n'
	else
		gate "subscriptions renewal compare" bash "$SELF_DIR/subscriptions-renewal-gate.sh" compare --ref "$REF_WP" --target "$TARGET_WP" --ref-subscription-id "$SUBSCRIPTIONS_REF_SUBSCRIPTION_ID" --target-subscription-id "$SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID" --out-dir "$FULL_EVIDENCE_OUT_DIR/subscriptions-renewal"
	fi
	if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_SESSION" ]; then
		record_playwriter_block "plugin-active settings screen (reference)" "plugin-active-settings-gate.sh" "--playwriter-session"
		record_playwriter_block "plugin-active settings screen (target)" "plugin-active-settings-gate.sh" "--playwriter-session"
	else
		if [ "$BROWSER_RUNNER" = "playwriter" ]; then
			gate_with_admin_credentials "plugin-active settings screen (reference)" "$REF_WP_ADMIN_USER" "$REF_WP_ADMIN_PASSWORD" bash "$SELF_DIR/plugin-active-settings-gate.sh" --target "$REF_WP" --target-url "$REF_URL" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings-reference"
			gate_with_admin_credentials "plugin-active settings screen (target)" "$TARGET_WP_ADMIN_USER" "$TARGET_WP_ADMIN_PASSWORD" bash "$SELF_DIR/plugin-active-settings-gate.sh" --target "$TARGET_WP" --target-url "$TARGET_URL" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_SESSION" --stage-plugin-active-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings"
		else
			gate_with_admin_credentials "plugin-active settings screen (reference)" "$REF_WP_ADMIN_USER" "$REF_WP_ADMIN_PASSWORD" bash "$SELF_DIR/plugin-active-settings-gate.sh" --target "$REF_WP" --target-url "$REF_URL" --browser-runner "$BROWSER_RUNNER" --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings-reference"
			gate_with_admin_credentials "plugin-active settings screen (target)" "$TARGET_WP_ADMIN_USER" "$TARGET_WP_ADMIN_PASSWORD" bash "$SELF_DIR/plugin-active-settings-gate.sh" --target "$TARGET_WP" --target-url "$TARGET_URL" --browser-runner "$BROWSER_RUNNER" --stage-plugin-active-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/plugin-active-settings"
		fi
	fi
	if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_CHECKOUT_SESSION" ]; then
		record_playwriter_block "LPM all-method checkout" "lpm-checkout-gate.sh" "--checkout-playwriter-session"
	else
		if [ "$BROWSER_RUNNER" = "playwriter" ]; then
			gate "LPM all-method checkout" bash "$SELF_DIR/lpm-checkout-gate.sh" --methods "$LPM_FULL_METHODS" --ref "$REF_WP" --target "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_CHECKOUT_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods"
		else
			gate "LPM all-method checkout" bash "$SELF_DIR/lpm-checkout-gate.sh" --methods "$LPM_FULL_METHODS" --ref "$REF_WP" --target "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --browser-runner "$BROWSER_RUNNER" --out-dir "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods"
		fi
	fi
	gate "multi-currency rates refresh" bash "$SELF_DIR/mc-rates-gate.sh" --ref "$REF_WP" --target "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --currency-from USD --currencies-to GBP,EUR --out-dir "$FULL_EVIDENCE_OUT_DIR/mc-rates"

	if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_CHECKOUT_SESSION" ]; then
		record_playwriter_block "token continuity cutover" "token-continuity-gate.sh" "--checkout-playwriter-session"
	elif [ -z "$TOKEN_CONTINUITY_CUSTOMER_ID" ]; then
		record "token continuity cutover" BLOCKED
		printf '      pass --token-customer-id and token source fixture inputs to run token-continuity-gate.sh\n'
	elif [ -n "$TOKEN_CONTINUITY_SUBSCRIPTION_ID" ]; then
		if [ "$BROWSER_RUNNER" = "playwriter" ]; then
			gate "token continuity cutover" bash "$SELF_DIR/token-continuity-gate.sh" --target "$TARGET_WP" --customer-id "$TOKEN_CONTINUITY_CUSTOMER_ID" --source-flow "${TOKEN_CONTINUITY_SOURCE_FLOW//_/-}" --subscription-id "$TOKEN_CONTINUITY_SUBSCRIPTION_ID" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_CHECKOUT_SESSION" --stage-sepa-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
		else
			gate "token continuity cutover" bash "$SELF_DIR/token-continuity-gate.sh" --target "$TARGET_WP" --customer-id "$TOKEN_CONTINUITY_CUSTOMER_ID" --source-flow "${TOKEN_CONTINUITY_SOURCE_FLOW//_/-}" --subscription-id "$TOKEN_CONTINUITY_SUBSCRIPTION_ID" --browser-runner "$BROWSER_RUNNER" --stage-sepa-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
		fi
	elif [ "$TOKEN_CONTINUITY_SOURCE_FLOW" = "provider_setup_intent" ] && [ -z "$TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID" ]; then
		record "token continuity cutover" BLOCKED
		printf '      pass --token-renewal-product-id for provider setup-intent token-continuity evidence\n'
	elif [ "$TOKEN_CONTINUITY_SOURCE_FLOW" != "provider_setup_intent" ] && { [ -z "$TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID" ] || [ -z "$TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID" ]; }; then
		record "token continuity cutover" BLOCKED
		printf '      pass --token-checkout-product-id and --token-renewal-product-id, or pass --token-subscription-id, to run token-continuity-gate.sh\n'
	elif [ "$TOKEN_CONTINUITY_SOURCE_FLOW" = "provider_setup_intent" ]; then
		if [ "$BROWSER_RUNNER" = "playwriter" ]; then
			gate "token continuity cutover" bash "$SELF_DIR/token-continuity-gate.sh" --target "$TARGET_WP" --customer-id "$TOKEN_CONTINUITY_CUSTOMER_ID" --source-flow provider-setup-intent --renewal-product-id "$TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_CHECKOUT_SESSION" --stage-sepa-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
		else
			gate "token continuity cutover" bash "$SELF_DIR/token-continuity-gate.sh" --target "$TARGET_WP" --customer-id "$TOKEN_CONTINUITY_CUSTOMER_ID" --source-flow provider-setup-intent --renewal-product-id "$TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID" --browser-runner "$BROWSER_RUNNER" --stage-sepa-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
		fi
	else
		if [ "$BROWSER_RUNNER" = "playwriter" ]; then
			gate "token continuity cutover" bash "$SELF_DIR/token-continuity-gate.sh" --target "$TARGET_WP" --customer-id "$TOKEN_CONTINUITY_CUSTOMER_ID" --source-flow "${TOKEN_CONTINUITY_SOURCE_FLOW//_/-}" --checkout-product-id "$TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID" --renewal-product-id "$TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_CHECKOUT_SESSION" --stage-sepa-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
		else
			gate "token continuity cutover" bash "$SELF_DIR/token-continuity-gate.sh" --target "$TARGET_WP" --customer-id "$TOKEN_CONTINUITY_CUSTOMER_ID" --source-flow "${TOKEN_CONTINUITY_SOURCE_FLOW//_/-}" --checkout-product-id "$TOKEN_CONTINUITY_CHECKOUT_PRODUCT_ID" --renewal-product-id "$TOKEN_CONTINUITY_RENEWAL_PRODUCT_ID" --browser-runner "$BROWSER_RUNNER" --stage-sepa-fixture --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
		fi
	fi

	if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_SESSION" ]; then
		record_playwriter_block "A5f cutover rehearsal" "a5f-cutover-rehearsal.py" "--playwriter-session"
	else
		if [ "$BROWSER_RUNNER" = "playwriter" ]; then
			gate_with_admin_credentials "A5f cutover rehearsal" "$TARGET_WP_ADMIN_USER" "$TARGET_WP_ADMIN_PASSWORD" python3 "$SELF_DIR/a5f-cutover-rehearsal.py" --target-wp "$TARGET_WP" --target-url "$TARGET_URL" --store-dir "$REPO_ROOT" --browser-runner "$BROWSER_RUNNER" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5f-cutover"
		else
			gate_with_admin_credentials "A5f cutover rehearsal" "$TARGET_WP_ADMIN_USER" "$TARGET_WP_ADMIN_PASSWORD" python3 "$SELF_DIR/a5f-cutover-rehearsal.py" --target-wp "$TARGET_WP" --target-url "$TARGET_URL" --store-dir "$REPO_ROOT" --browser-runner "$BROWSER_RUNNER" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5f-cutover"
		fi
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
	run_bundle_size_evidence
	run_perf_surface_evidence
	run_a4aq_accumulated_evidence
	gate "critical flows inventory" python3 "$REPO_ROOT/tools/woopayments-critical-flows/test-inventory.py"
	gate "critical flows fixtures (reference)" env EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash "$REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh" fixture_all ref
	gate "critical flows fixtures (target)" env EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash "$REPO_ROOT/tools/woopayments-critical-flows/setup/fixtures.sh" fixture_all target
	if [ -z "$SUBSCRIPTIONS_REF_SUBSCRIPTION_ID" ] || [ -z "$SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID" ]; then
		record "critical flows evidence context" BLOCKED
		printf '      pass --ref-subscription-id and --target-subscription-id to bind critical-flow evidence to current fixtures\n'
	else
		gate "critical flows evidence context" python3 "$REPO_ROOT/tools/woopayments-critical-flows/evidence_context.py" create --repo "$REPO_ROOT" --out "$CRITICAL_FLOW_CONTEXT_FILE" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --ref-subscription-id "$SUBSCRIPTIONS_REF_SUBSCRIPTION_ID" --target-subscription-id "$SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID"
	fi
	if [ "$BROWSER_RUNNER" != "playwright" ]; then
		record "SC-04 context-bound saved-card browser evidence" BLOCKED
		printf '      SC-04 final evidence requires the process-local Playwright runner\n'
	else
		gate "SC-04 context-bound saved-card browser evidence" python3 "$SELF_DIR/sc04-saved-card-gate.py" --repo "$REPO_ROOT" --context-file "$CRITICAL_FLOW_CONTEXT_FILE" --ref-wp "$REF_WP" --target-wp "$TARGET_WP" --ref-url "$REF_URL" --target-url "$TARGET_URL" --ref-subscription-id "$SUBSCRIPTIONS_REF_SUBSCRIPTION_ID" --target-subscription-id "$SUBSCRIPTIONS_TARGET_SUBSCRIPTION_ID" --browser-runner "$BROWSER_RUNNER" --out-dir "$SC04_EVIDENCE_DIR"
	fi
	build_critical_flow_agent_result_args
	gate "critical flows agent result synthesis" python3 "$REPO_ROOT/tools/woopayments-critical-flows/build-agent-results.py" "${CRITICAL_FLOW_AGENT_RESULT_ARGS[@]}"
	gate "critical flows full run" env EVIDENCE_DIR="$CRITICAL_FLOWS_EVIDENCE_DIR" REF_WP_COMMAND="$REF_WP" TARGET_WP_COMMAND="$TARGET_WP" bash "$REPO_ROOT/tools/woopayments-critical-flows/run.sh" --store both --layer all --ref-url "$REF_URL" --target-url "$TARGET_URL" --agent-results-dir "$CRITICAL_FLOWS_AGENT_RESULTS_DIR" --context-file "$CRITICAL_FLOW_CONTEXT_FILE"
	run_quality_evidence
}

acknowledge_manual_evidence_limitations() {
	local classification_file status label reason
	local remaining=()

	if [ "$FULL_EVIDENCE" -ne 1 ] || [ "$ACK_MANUAL_EVIDENCE_LIMITATIONS" -ne 1 ] || [ "${#BLOCKED[@]}" -eq 0 ]; then
		return
	fi

	mkdir -p "${GATE_LOG_DIR:-${TMPDIR:-$SELF_DIR/.tmp}}"
	classification_file="$(mktemp "${GATE_LOG_DIR:-${TMPDIR:-$SELF_DIR/.tmp}}/woopayments-manual-evidence-limitations.XXXXXX")"
	if ! python3 - "$FULL_EVIDENCE_OUT_DIR" "$FULL_EVIDENCE_OUT_DIR/manual-evidence-limitations.json" "$LPM_FULL_METHODS" "$REPO_ROOT" "${BLOCKED[@]}" > "$classification_file" <<'PY'; then
from __future__ import annotations

import json
import sys
from collections import Counter
from pathlib import Path
from typing import Any


out_dir = Path(sys.argv[1])
summary_path = Path(sys.argv[2])
expected_lpm_methods = tuple(method.strip() for method in sys.argv[3].split(",") if method.strip())
repo_root = Path(sys.argv[4])
labels = sys.argv[5:]
sys.path.insert(0, str(repo_root / "tools" / "woopayments-merge"))
from lpm_evidence import (  # noqa: E402
    MANUAL_BLOCKER_CODE,
    MANUAL_METHOD_CONTRACTS,
    context_from_payload,
    validate_manual_completion,
    validate_manual_pair,
)

accepted_lpm_profiles = {
    "p24": {"country": "PL", "capability": "p24_payments", "provisionable": True},
    "au_becs_debit": {"country": "AU", "capability": "au_becs_debit_payments", "provisionable": False},
    "grabpay": {"country": "SG", "capability": "grabpay_payments", "provisionable": False},
}
accepted_capability_statuses = {"missing", "unrequested", "pending", "inactive", "restricted", "rejected"}
accepted_critical_flows = {
    "SC-14-lpm-wave-1-checkout",
    "SS-10-sepa-token-renewal-cutover",
}


def read_json(path: Path) -> dict[str, Any] | None:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    return value if isinstance(value, dict) else None


def has_failures(payload: dict[str, Any]) -> bool:
    failures = payload.get("failures")
    return isinstance(failures, list) and bool(failures)


def has_empty_failure_list(payload: dict[str, Any]) -> bool:
    failures = payload.get("failures")
    return isinstance(failures, list) and not failures


def resolve_lpm_artifact(value: Any, expected: Path) -> Path | None:
    if not isinstance(value, str) or not value:
        return None

    path = Path(value)
    if not path.is_absolute():
        path = out_dir / "lpm-all-methods" / path

    try:
        return path if path.resolve() == expected.resolve() else None
    except OSError:
        return None


def valid_sepa_capability_blocker(detail: dict[str, Any]) -> bool:
    role = detail.get("role")
    expected = out_dir / "lpm-all-methods" / "lpm-fixtures" / f"{role}-sepa_debit-stage.json"
    path = resolve_lpm_artifact(detail.get("artifact"), expected)
    payload = read_json(path) if path else None
    if not payload:
        return False

    previous = payload.get("previous") if isinstance(payload.get("previous"), dict) else {}
    capability_key = "sepa_debit_payments"
    capability_status = str(previous.get("capability_status") or "missing").lower()
    errors = payload.get("errors") if isinstance(payload.get("errors"), list) else []
    capabilities = (
        previous.get("account_cache", {}).get("data", {}).get("capabilities", {})
        if isinstance(previous.get("account_cache"), dict)
        else {}
    )

    return (
        payload.get("success") is False
        and payload.get("mode") == "stage-lpm-fixture"
        and payload.get("method") == "sepa_debit"
        and previous.get("capability_key") == capability_key
        and capability_status in accepted_capability_statuses
        and (
            capability_status == "missing"
            or (isinstance(capabilities, dict) and str(capabilities.get(capability_key) or "").lower() == capability_status)
        )
        and any(
            isinstance(error, str)
            and "connected WooPayments account capability" in error
            and capability_key in error
            for error in errors
        )
    )


def valid_account_profile_blocker(detail: dict[str, Any]) -> bool:
    role = detail.get("role")
    method = detail.get("method")
    rule = accepted_lpm_profiles.get(str(method))
    if not rule:
        return False

    expected = out_dir / "lpm-all-methods" / f"{role}-{method}-account-profile.json"
    path = resolve_lpm_artifact(detail.get("artifact"), expected)
    payload = read_json(path) if path else None
    if not payload:
        return False

    profile = payload.get("profile") if isinstance(payload.get("profile"), dict) else {}
    checks = payload.get("checks") if isinstance(payload.get("checks"), dict) else {}
    country_check = checks.get("country") if isinstance(checks.get("country"), dict) else {}
    capability_check = checks.get(rule["capability"]) if isinstance(checks.get(rule["capability"]), dict) else {}
    capability_status = str(
        capability_check.get("status") or capability_check.get("capability_status") or ""
    ).lower()
    has_exact_account_blocker = (
        country_check.get("status") == "wrong_country"
        or capability_status in accepted_capability_statuses
    )

    return (
        payload.get("success") is True
        and payload.get("ready") is False
        and payload.get("status") == "blocked"
        and profile.get("id") == method
        and profile.get("country") == rule["country"]
        and profile.get("test_lab_provisionable") is rule["provisionable"]
        and has_exact_account_blocker
    )


def valid_manual_completion_blocker(
    detail: dict[str, Any], result: dict[str, Any], surface: str
) -> bool:
    role = str(detail.get("role") or "")
    method = str(detail.get("method") or "")
    if method not in MANUAL_METHOD_CONTRACTS:
        return False

    expected = out_dir / "lpm-all-methods" / f"{role}-{method}-{surface}.json"
    path = resolve_lpm_artifact(detail.get("artifact"), expected)
    artifact = read_json(path) if path else None
    if not artifact or artifact != result:
        return False

    context = context_from_payload(artifact)
    return (
        detail.get("code") == MANUAL_BLOCKER_CODE
        and detail.get("provenance") == artifact.get("manual_completion")
        and context.role == role
        and context.method == method
        and not validate_manual_completion(artifact, context)
    )


def accept_lpm_all_methods() -> str | None:
    payload = read_json(out_dir / "lpm-all-methods" / "lpm-checkout-gate.json")
    if (
        not payload
        or payload.get("schema") != "woopayments_lpm_checkout_gate_rollup.v1"
        or payload.get("status") not in {"blocked", "incomplete"}
        or not has_empty_failure_list(payload)
        or len(expected_lpm_methods) != len(set(expected_lpm_methods))
    ):
        return None

    cleanup_restore = payload.get("cleanup_restore")
    cleanup_artifact = read_json(out_dir / "lpm-all-methods" / "lpm-cleanup-restore.json")
    if (
        not isinstance(cleanup_restore, dict)
        or cleanup_restore.get("schema") != "woopayments_lpm_cleanup_restore.v1"
        or cleanup_restore.get("status") != "pass"
        or cleanup_artifact != cleanup_restore
    ):
        return None

    expected_identities = {
        (role, method)
        for role in ("reference", "target")
        for method in expected_lpm_methods
    }
    results = payload.get("results")
    if not isinstance(results, list):
        return None

    result_identities = set()
    passing_result_identities = set()
    manual_result_identities = set()
    results_by_identity: dict[tuple[Any, Any], dict[str, Any]] = {}
    for result in results:
        if not isinstance(result, dict) or not has_empty_failure_list(result):
            return None
        identity = (result.get("role"), result.get("method"))
        if (
            identity not in expected_identities
            or identity in result_identities
        ):
            return None
        result_identities.add(identity)
        results_by_identity[identity] = result
        if result.get("status") == "pass":
            passing_result_identities.add(identity)
        elif (
            result.get("status") == "blocked"
            and result.get("blocker_code") == MANUAL_BLOCKER_CODE
            and result.get("method") in MANUAL_METHOD_CONTRACTS
        ):
            manual_result_identities.add(identity)
        else:
            return None

    blockers = payload.get("blockers")
    if not isinstance(blockers, list) or not blockers:
        return None

    blocker_details = payload.get("blocker_details")
    if not isinstance(blocker_details, list) or len(blocker_details) != len(blockers):
        return None
    if Counter(blockers) != Counter(
        detail.get("message") for detail in blocker_details if isinstance(detail, dict)
    ):
        return None

    blocker_identities = set()
    account_blocker_identities = set()
    manual_blocker_identities = set()
    surface = str(payload.get("surface") or "")
    if surface not in {"classic", "blocks"}:
        return None
    for detail in blocker_details:
        if not isinstance(detail, dict):
            return None
        role = detail.get("role")
        method = detail.get("method")
        identity = (role, method)
        if identity not in expected_identities or identity in blocker_identities:
            return None
        blocker_identities.add(identity)

        code = detail.get("code")
        if code == "account_capability_unavailable" and method == "sepa_debit":
            if not valid_sepa_capability_blocker(detail):
                return None
            account_blocker_identities.add(identity)
        elif code == "account_profile_ineligible" and method in accepted_lpm_profiles:
            if not valid_account_profile_blocker(detail):
                return None
            account_blocker_identities.add(identity)
        elif code == MANUAL_BLOCKER_CODE and method in MANUAL_METHOD_CONTRACTS:
            result = results_by_identity.get(identity)
            if not result or not valid_manual_completion_blocker(detail, result, surface):
                return None
            manual_blocker_identities.add(identity)
        else:
            return None

    if account_blocker_identities & result_identities:
        return None
    if manual_blocker_identities != manual_result_identities:
        return None
    if passing_result_identities & blocker_identities:
        return None
    if passing_result_identities | account_blocker_identities | manual_blocker_identities != expected_identities:
        return None

    for method in MANUAL_METHOD_CONTRACTS:
        method_identities = {
            ("reference", method),
            ("target", method),
        }
        classified_identities = manual_blocker_identities & method_identities
        if classified_identities and classified_identities != method_identities:
            return None
        if classified_identities:
            if validate_manual_pair(
                results_by_identity[("reference", method)],
                results_by_identity[("target", method)],
                method,
            ):
                return None

    return "LPM blockers are limited to validated payment-method account/profile prerequisites and symmetric customer authorization that requires manual completion."


def accept_token_continuity() -> str | None:
    rollup_path = out_dir / "token-continuity" / "token-continuity-gate.json"
    if rollup_path.exists():
        return None

    stage = read_json(out_dir / "token-continuity" / "sepa-fixture-stage.json")
    if not stage or stage.get("success") is not False or stage.get("method") != "sepa_debit":
        return None

    previous = stage.get("previous") if isinstance(stage.get("previous"), dict) else {}
    capability_key = "sepa_debit_payments"
    capability_status = str(previous.get("capability_status") or "missing").lower()
    capabilities = (
        previous.get("account_cache", {}).get("data", {}).get("capabilities", {})
        if isinstance(previous.get("account_cache"), dict)
        else {}
    )
    errors = stage.get("errors")
    if not isinstance(errors, list):
        return None
    if (
        stage.get("mode") != "stage-lpm-fixture"
        or previous.get("capability_key") != capability_key
        or capability_status not in accepted_capability_statuses
        or (
            capability_status != "missing"
            and (
                not isinstance(capabilities, dict)
                or str(capabilities.get(capability_key) or "").lower() != capability_status
            )
        )
        or not any(
            isinstance(error, str)
            and "connected WooPayments account capability" in error
            and capability_key in error
            for error in errors
        )
    ):
        return None

    return "Token continuity is blocked only by the accepted SEPA fixture/account prerequisite."


def valid_target_only_token_continuity_pass() -> bool:
    payload = read_json(out_dir / "token-continuity" / "token-continuity-gate.json")
    if (
        not payload
        or payload.get("schema") != "woopayments_token_continuity_gate_rollup.v1"
        or payload.get("status") != "pass"
        or payload.get("failures") != []
        or payload.get("blockers") != []
    ):
        return False

    token_id = payload.get("token_id")
    source = payload.get("source_token") if isinstance(payload.get("source_token"), dict) else {}
    native = (
        payload.get("native_token_loader")
        if isinstance(payload.get("native_token_loader"), dict)
        else {}
    )
    render = (
        payload.get("render_payment_methods")
        if isinstance(payload.get("render_payment_methods"), dict)
        else {}
    )
    render_page = render.get("page") if isinstance(render.get("page"), dict) else {}
    methods = (
        render_page.get("payment_methods")
        if isinstance(render_page.get("payment_methods"), dict)
        else {}
    )
    renewal = payload.get("renewal") if isinstance(payload.get("renewal"), dict) else {}

    return (
        payload.get("source_flow") == "provider_setup_intent"
        and isinstance(token_id, int)
        and token_id > 0
        and source.get("success") is True
        and source.get("source_payment_method_customer_ready") is True
        and str(source.get("payment_method_id") or "").startswith("pm_")
        and native.get("success") is True
        and native.get("token_id") == token_id
        and native.get("gateway_id") == "woocommerce_payments_sepa_debit"
        and native.get("token_type") == "wcpay_sepa"
        and str(native.get("token_class") or "").endswith("WooPaymentsSepaToken")
        and render.get("status") == "pass"
        and render.get("token_id") == token_id
        and render.get("token_visible") is True
        and methods.get("token_visible") is True
        and renewal.get("success") is True
        and renewal.get("renewal_processing_model") == "asynchronous_processing"
        and renewal.get("success_checks_failed") == []
        and isinstance(renewal.get("renewal_order_id"), int)
        and renewal.get("renewal_order_id", 0) > 0
    )


def accept_critical_flows() -> str | None:
    payload = read_json(out_dir / "critical-flows" / "rollup.json")
    context = read_json(out_dir / "critical-flow-context.json")
    flow_dir = repo_root / "tools" / "woopayments-critical-flows" / "flows"
    flow_paths = sorted(flow_dir.glob("*.sh")) + sorted(flow_dir.glob("*.md"))
    expected_flows = {path.stem for path in flow_paths}
    if (
        not payload
        or not context
        or payload.get("schema") != "woopayments_critical_flows_rollup.v1"
        or payload.get("status") != "blocked"
        or not expected_flows
        or len(expected_flows) != len(flow_paths)
        or not accepted_critical_flows <= expected_flows
    ):
        return None
    if payload.get("context_sha256") != context.get("context_sha256"):
        return None
    if payload.get("aggregate_run_id") != context.get("aggregate_run_id"):
        return None

    summary = payload.get("summary")
    if not isinstance(summary, dict):
        return None

    results = payload.get("results")
    if not isinstance(results, list):
        return None

    expected_identities = {
        (flow, store)
        for flow in expected_flows
        for store in ("ref", "target")
    }
    seen_identities = set()
    status_counts: Counter[str] = Counter()
    flow_statuses: dict[str, dict[str, str]] = {}
    for result in results:
        if not isinstance(result, dict):
            return None
        status = str(result.get("status") or "").upper()
        flow = str(result.get("flow") or "")
        store = str(result.get("store") or "")
        identity = (flow, store)
        if identity not in expected_identities or identity in seen_identities:
            return None
        if status not in {"PASS", "BLOCKED"}:
            return None
        seen_identities.add(identity)
        status_counts[status] += 1
        if flow not in accepted_critical_flows:
            if status != "PASS":
                return None
            continue
        if store in flow_statuses.setdefault(flow, {}):
            return None
        flow_statuses[flow][store] = status

    if seen_identities != expected_identities:
        return None
    if (
        summary.get("passed") != status_counts["PASS"]
        or summary.get("failed") != 0
        or summary.get("blocked") != status_counts["BLOCKED"]
    ):
        return None

    sc14 = flow_statuses.get("SC-14-lpm-wave-1-checkout")
    if sc14 != {"ref": "BLOCKED", "target": "BLOCKED"} or accept_lpm_all_methods() is None:
        return None

    ss10 = flow_statuses.get("SS-10-sepa-token-renewal-cutover")
    if ss10 == {"ref": "BLOCKED", "target": "BLOCKED"}:
        if accept_token_continuity() is None:
            return None
    elif ss10 == {"ref": "BLOCKED", "target": "PASS"}:
        if not valid_target_only_token_continuity_pass():
            return None
    else:
        return None

    return "Critical-flow blockers are inherited only from accepted LPM/SEPA manual evidence rows."


def classify(label: str) -> str | None:
    if label == "LPM all-method checkout":
        return accept_lpm_all_methods()
    if label == "token continuity cutover":
        return accept_token_continuity()
    if label == "critical flows full run":
        return accept_critical_flows()
    return None


def shell_field(value: str) -> str:
    return value.replace("\t", " ").replace("\n", " ")


accepted: list[dict[str, str]] = []
blocked: list[dict[str, str]] = []
for blocked_label in labels:
    accepted_reason = classify(blocked_label)
    entry = {
        "label": blocked_label,
        "reason": accepted_reason or "No accepted manual-evidence classification matched.",
    }
    if accepted_reason:
        accepted.append(entry)
        print(f"ACCEPTED\t{shell_field(blocked_label)}\t{shell_field(accepted_reason)}")
    else:
        blocked.append(entry)
        print(f"BLOCKED\t{shell_field(blocked_label)}\t{shell_field(entry['reason'])}")

summary_path.parent.mkdir(parents=True, exist_ok=True)
summary_path.write_text(
    json.dumps(
        {
            "schema": "woopayments_manual_evidence_limitations.v1",
            "accepted": accepted,
            "blocked": blocked,
        },
        indent=2,
        sort_keys=True,
    )
    + "\n",
    encoding="utf-8",
)
PY
		rm -f "$classification_file"
		return
	fi

	while IFS=$'\t' read -r status label reason; do
		case "$status" in
			ACCEPTED)
				ACKNOWLEDGED+=( "$label: $reason" )
				;;
			BLOCKED)
				remaining+=( "$label" )
				;;
		esac
	done < "$classification_file"
	rm -f "$classification_file"

	BLOCKED=()
	if [ "${#remaining[@]}" -ne 0 ]; then
		BLOCKED=( "${remaining[@]}" )
	fi
}

if [ "$PRINT_FULL_EVIDENCE_PLAN" -eq 1 ]; then
	print_full_evidence_plan
	exit 0
fi
if ! validate_execution_scope; then
	exit 3
fi
if [ "$VALIDATE_SCOPE_ONLY" -eq 1 ]; then
	printf 'Execution scope validated: %s reference and %s target.\n' "$REF_URL" "$TARGET_URL"
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
	acknowledge_manual_evidence_limitations

	echo
	echo "Summary: ${#PASS[@]} passed, ${#FAILED[@]} failed, ${#BLOCKED[@]} blocked, ${#ACKNOWLEDGED[@]} acknowledged manual."
	if [ "${#ACKNOWLEDGED[@]}" -ne 0 ]; then
		echo "Acknowledged manual evidence limitations:"
		printf '  - %s\n' "${ACKNOWLEDGED[@]}"
	fi
	if [ "${#FAILED[@]}" -ne 0 ]; then echo "RESULT: FAIL (regressions present)."; exit 1; fi
	if [ "${#BLOCKED[@]}" -ne 0 ]; then echo "RESULT: INCOMPLETE (preconditions unmet - not a regression)."; exit 3; fi
	if [ "$FULL_EVIDENCE" -eq 1 ]; then
		if [ "${#ACKNOWLEDGED[@]}" -ne 0 ]; then
			echo "RESULT: PASS WITH ACKNOWLEDGED MANUAL EVIDENCE LIMITATIONS - automated full-evidence gates passed outside accepted manual-testing rows."
		else
			echo "RESULT: PASS - full-evidence gates passed for the local parity/readiness surfaces."
		fi
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
gate "drift gate (BC + tracks)" env WCPAY_SRC="$WCPAY_SOURCE_ROOT" WCPAY_SOURCE_REF="${WCPAY_EXTENSION_REF:-10.8.0}" bash "$SELF_DIR/bc-drift-gate.sh"
gate "subsystem disposition inventory" bash "$SELF_DIR/subsystem-disposition-gate.sh"
if [ "$MODE" = "self" ]; then
	gate "hook-shape parity" bash "$SELF_DIR/hook-shape-parity.sh" --ref "$REF_WP" --target "$TARGET_WP" --self-check
	gate "REST route parity" bash "$SELF_DIR/rest-route-parity.sh" --ref "$REF_WP" --target "$TARGET_WP" --self-check
else
	gate "hook-shape parity" bash "$SELF_DIR/hook-shape-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
	gate "REST route parity" bash "$SELF_DIR/rest-route-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"
fi
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

# 4. Narrow query-count smoke through the pre-bootstrap MU probe on both roles.
perf_smoke_dir="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-perf-smoke.XXXXXX")"
perf_smoke_ref="$perf_smoke_dir/reference.json"
perf_smoke_target="$perf_smoke_dir/target.json"
if gate "perf smoke capture (reference)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$REF_WP" --out "$perf_smoke_ref"; then
	if gate "perf smoke capture (target)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$TARGET_WP" --out "$perf_smoke_target"; then
		gate "perf smoke (gateway query-count)" bash "$SELF_DIR/perf-surface-gate.sh" compare --ref "$perf_smoke_ref" --target "$perf_smoke_target"
	fi
fi
rm -rf "$perf_smoke_dir"

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
