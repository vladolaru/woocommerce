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
#   --full-evidence  also run final readiness gates that need broader fixtures/browser evidence.
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
TRACKS_OUT_DIR="${TRACKS_OUT_DIR:-${TMPDIR:?TMPDIR is required for Tracks evidence}/woopayments-tracks-parity}"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
TOKEN_CONTINUITY_CUSTOMER_ID="${TOKEN_CONTINUITY_CUSTOMER_ID:-}"
TOKEN_CONTINUITY_SUBSCRIPTION_ID="${TOKEN_CONTINUITY_SUBSCRIPTION_ID:-}"
FULL_EVIDENCE_OUT_DIR="${FULL_EVIDENCE_OUT_DIR:-${TMPDIR:-$SELF_DIR/.tmp}/woopayments-final-evidence}"
TARGET_URL="${TARGET_URL:-http://store8889.localhost:8889}"
LPM_FULL_METHODS="sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay"
WCPAY_REPO="${WCPAY_REPO:-$REPO_ROOT/../woocommerce-payments}"
BUNDLE_BUDGET="${BUNDLE_BUDGET:-$SELF_DIR/a4aq-bundle-budget.json}"

usage() {
	cat >&2 <<'USAGE'
usage: verify.sh (--self-check WP | --ref WP --target WP) [--with-tracks] [--full-evidence] [options]

Options:
  --with-tracks                 Include sink-based Tracks parity for the deterministic charge flow.
  --tracks-ref-store-id ID      Filter reference Tracks capture to this woocommerce_store_id.
  --tracks-target-store-id ID   Filter target Tracks capture to this woocommerce_store_id.
  --full-evidence               Run final readiness gates beyond the base deterministic loop.
  --print-full-evidence-plan    Print the final readiness gate plan and exit.
  --playwriter-session ID       Playwriter session for browser-dependent final gates.
  --token-customer-id ID        Customer fixture ID for token-continuity evidence.
  --token-subscription-id ID    Subscription fixture ID for token-continuity evidence.
  --full-evidence-out-dir DIR   Evidence output directory for final gates.
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
		--token-customer-id) TOKEN_CONTINUITY_CUSTOMER_ID="${2:-}"; shift 2 ;;
		--token-subscription-id) TOKEN_CONTINUITY_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--full-evidence-out-dir) FULL_EVIDENCE_OUT_DIR="${2:-}"; shift 2 ;;
		*) echo "Unknown arg: $1" >&2; usage; exit 2 ;;
	esac
done
if [ -z "$MODE" ] || [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	usage
	exit 2
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

# Run a gate command; classify by exit code (0 PASS, 2/3 BLOCKED-preconditions, else FAIL).
gate() { # $1 label ; $2.. command
	local label="$1"; shift
	local rc out_file tmp_base
	tmp_base="${TMPDIR:-$SELF_DIR/.tmp}"
	mkdir -p "$tmp_base"
	out_file="$(mktemp "$tmp_base/woopayments-merge-gate.log.XXXXXX")"
	printf '  [RUN    ] %s\n' "$label"
	"$@" 2>&1 | sed 's/^/      /' | tee "$out_file"
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

tracks_normalize() {
	local role="$1" store_id="$2" out_file="$3" raw rc
	local normalize_args=()

	if [ -n "$store_id" ]; then
		normalize_args+=(--store "$store_id")
	fi

	raw="$(bash "$SELF_DIR/tracks-parity.sh" normalize "${normalize_args[@]}" 2>"$out_file.stderr")"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		tracks_block "$role Tracks normalization failed: $(cat "$out_file.stderr" 2>/dev/null)"
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

print_full_evidence_plan() {
	cat <<PLAN
WooPayments final-evidence plan
mode=$MODE
out_dir=$FULL_EVIDENCE_OUT_DIR

bash $SELF_DIR/lpm-checkout-gate.sh --methods $LPM_FULL_METHODS --ref "$REF_WP" --target "$TARGET_WP" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods"
bash $SELF_DIR/mc-rates-gate.sh --ref "$REF_WP" --target "$TARGET_WP" --currency-from USD --currencies-to GBP,EUR --out-dir "$FULL_EVIDENCE_OUT_DIR/mc-rates"
bash $SELF_DIR/subscriptions-renewal-gate.sh preflight --ref "$REF_WP" --target "$TARGET_WP" --out-dir "$FULL_EVIDENCE_OUT_DIR/subscriptions-renewal"
bash $SELF_DIR/token-continuity-gate.sh --target "$TARGET_WP" --customer-id "${TOKEN_CONTINUITY_CUSTOMER_ID:-<required>}" --subscription-id "${TOKEN_CONTINUITY_SUBSCRIPTION_ID:-<required>}" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/token-continuity"
python3 $SELF_DIR/a5f-cutover-rehearsal.py --target-wp "$TARGET_WP" --target-url "$TARGET_URL" --store-dir "$REPO_ROOT" --playwriter-session "${PLAYWRITER_SESSION:-<required>}" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5f-cutover"
python3 $SELF_DIR/a5g-multisite-runtime-gate.py --repo "$REPO_ROOT" --wcpay-repo "$WCPAY_REPO" --out-dir "$FULL_EVIDENCE_OUT_DIR/a5g-multisite-runtime"
bash $SELF_DIR/dispute-e2e-gate.sh --ref "$REF_WP" --target "$TARGET_WP"
bash $SELF_DIR/payout-evidence-gate.sh --wp "$REF_WP" --label reference
bash $SELF_DIR/payout-evidence-gate.sh --wp "$TARGET_WP" --label target --native
bash $SELF_DIR/converted-currency-gate.sh --ref "$REF_WP" --target "$TARGET_WP" --currency GBP
bash $SELF_DIR/bundle-size-gate.sh capture --repo "$WCPAY_REPO" --out "$FULL_EVIDENCE_OUT_DIR/bundle-size/reference.json" --profile wcpay-plugin
bash $SELF_DIR/bundle-size-gate.sh capture --repo "$REPO_ROOT" --out "$FULL_EVIDENCE_OUT_DIR/bundle-size/target.json" --profile wc-core
bash $SELF_DIR/bundle-size-gate.sh compare --ref "$FULL_EVIDENCE_OUT_DIR/bundle-size/reference.json" --target "$FULL_EVIDENCE_OUT_DIR/bundle-size/target.json" --budget "$BUNDLE_BUDGET"
bash $SELF_DIR/perf-surface-gate.sh capture --wp "$REF_WP" --out "$FULL_EVIDENCE_OUT_DIR/perf-surface/reference.json"
bash $SELF_DIR/perf-surface-gate.sh capture --wp "$TARGET_WP" --out "$FULL_EVIDENCE_OUT_DIR/perf-surface/target.json"
bash $SELF_DIR/perf-surface-gate.sh compare --ref "$FULL_EVIDENCE_OUT_DIR/perf-surface/reference.json" --target "$FULL_EVIDENCE_OUT_DIR/perf-surface/target.json"
python3 $REPO_ROOT/tools/woopayments-critical-flows/test-inventory.py
PLAN
}

run_bundle_size_evidence() {
	local bundle_dir reference_json target_json
	bundle_dir="$FULL_EVIDENCE_OUT_DIR/bundle-size"
	reference_json="$bundle_dir/reference.json"
	target_json="$bundle_dir/target.json"
	mkdir -p "$bundle_dir"

	if [ ! -f "$WCPAY_REPO/woocommerce-payments.php" ]; then
		record "bundle size capture (reference)" BLOCKED
		printf '      set WCPAY_REPO to a local WooPayments plugin checkout before running bundle-size-gate.sh\n'
	else
		gate "bundle size capture (reference)" bash "$SELF_DIR/bundle-size-gate.sh" capture --repo "$WCPAY_REPO" --out "$reference_json" --profile wcpay-plugin
	fi

	gate "bundle size capture (target)" bash "$SELF_DIR/bundle-size-gate.sh" capture --repo "$REPO_ROOT" --out "$target_json" --profile wc-core

	if [ ! -s "$reference_json" ] || [ ! -s "$target_json" ]; then
		record "bundle size compare" BLOCKED
		printf '      bundle capture JSON is missing; run both bundle-size-gate.sh capture commands before compare\n'
	elif [ -f "$BUNDLE_BUDGET" ]; then
		gate "bundle size compare" bash "$SELF_DIR/bundle-size-gate.sh" compare --ref "$reference_json" --target "$target_json" --budget "$BUNDLE_BUDGET"
	else
		record "bundle size compare" BLOCKED
		printf '      bundle budget missing: %s\n' "$BUNDLE_BUDGET"
	fi
}

run_perf_surface_evidence() {
	local perf_dir reference_json target_json
	perf_dir="$FULL_EVIDENCE_OUT_DIR/perf-surface"
	reference_json="$perf_dir/reference.json"
	target_json="$perf_dir/target.json"
	mkdir -p "$perf_dir"

	gate "perf surface capture (reference)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$REF_WP" --out "$reference_json"
	gate "perf surface capture (target)" bash "$SELF_DIR/perf-surface-gate.sh" capture --wp "$TARGET_WP" --out "$target_json"

	if [ ! -s "$reference_json" ] || [ ! -s "$target_json" ]; then
		record "perf surface compare" BLOCKED
		printf '      perf capture JSON is missing; run both perf-surface-gate.sh capture commands before compare\n'
	else
		gate "perf surface compare" bash "$SELF_DIR/perf-surface-gate.sh" compare --ref "$reference_json" --target "$target_json"
	fi
}

run_full_evidence_gates() {
	if [ "$MODE" != "cross" ]; then
		record "full-evidence gates require --ref/--target" BLOCKED
		return
	fi

	mkdir -p "$FULL_EVIDENCE_OUT_DIR"
	gate "critical flows inventory" python3 "$REPO_ROOT/tools/woopayments-critical-flows/test-inventory.py"
	gate "subscriptions renewal preflight" bash "$SELF_DIR/subscriptions-renewal-gate.sh" preflight --ref "$REF_WP" --target "$TARGET_WP" --out-dir "$FULL_EVIDENCE_OUT_DIR/subscriptions-renewal"
	gate "LPM all-method checkout" bash "$SELF_DIR/lpm-checkout-gate.sh" --methods "$LPM_FULL_METHODS" --ref "$REF_WP" --target "$TARGET_WP" --playwriter-session "$PLAYWRITER_SESSION" --out-dir "$FULL_EVIDENCE_OUT_DIR/lpm-all-methods"
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
	run_bundle_size_evidence
	run_perf_surface_evidence
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

echo "WooPayments-merge verification loop - mode: $MODE"
echo

TRACKS_CAPTURE_READY=0
if [ "$WITH_TRACKS" -eq 1 ]; then
	if [ "$MODE" != "cross" ]; then
		tracks_block "--with-tracks requires cross-store mode with --ref and --target"
	else
		mkdir -p "$TRACKS_OUT_DIR"
		rm -f "$TRACKS_REF_FILE" "$TRACKS_TARGET_FILE" "$TRACKS_REF_FILE.stderr" "$TRACKS_TARGET_FILE.stderr"
		if tracks_reset "reference"; then
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

if [ "$FULL_EVIDENCE" -eq 1 ]; then
	run_full_evidence_gates
fi

echo
echo "Summary: ${#PASS[@]} passed, ${#FAILED[@]} failed, ${#BLOCKED[@]} blocked."
if [ "${#FAILED[@]}" -ne 0 ]; then echo "RESULT: FAIL (regressions present)."; exit 1; fi
if [ "${#BLOCKED[@]}" -ne 0 ]; then echo "RESULT: INCOMPLETE (preconditions unmet - not a regression)."; exit 3; fi
echo "RESULT: PASS - the deterministic gates pass on the captured surfaces (Tier A/B)."
echo "  NOTE: this is NOT full merge verification. Financial reconciliation now checks the widened"
echo "  money matrix for the supplied orders, but full refund/dispute/payout/multi-currency coverage"
echo "  still requires those flows to be driven. Browser checkout (incl. 3DS/SCA), Tracks for each"
echo "  surviving surface, broad perf, and bundle size need their dedicated gates - see HARNESS.md."
exit 0
