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
#   --with-tracks    also run the Tracks-parity gate (installs the capture drop-in + enables
#                    tracking on the store(s), then restores). Off by default since it mutates state.
#
# Exit: 0 only if every gate PASSED. Any FAIL or BLOCKED -> non-zero (so a CI loop stops).
# PRECONDITIONS (see HARNESS.md): connected test account, event listener running, valid host
# `stripe login` for the financial gate. Local-only; never the remote WPCOM sandbox.

set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MODE=""
REF_WP=""
TARGET_WP=""
WITH_TRACKS=0
while [ "$#" -gt 0 ]; do
	case "$1" in
		--self-check) MODE="self"; REF_WP="$2"; TARGET_WP="$2"; shift 2 ;;
		--ref) MODE="cross"; REF_WP="$2"; shift 2 ;;
		--target) TARGET_WP="$2"; shift 2 ;;
		--with-tracks) WITH_TRACKS=1; shift ;;
		*) echo "Unknown arg: $1" >&2; exit 2 ;;
	esac
done
if [ -z "$MODE" ] || [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	echo "usage: verify.sh (--self-check WP | --ref WP --target WP) [--with-tracks]" >&2
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

# 1. BC + Tracks static drift gate (source-level; independent of stores).
gate "drift gate (BC + tracks)" bash "$SELF_DIR/bc-drift-gate.sh"
gate "subsystem disposition inventory" bash "$SELF_DIR/subsystem-disposition-gate.sh"
gate "hook-shape parity" bash "$SELF_DIR/hook-shape-parity.sh" --ref "$REF_WP" --target "$TARGET_WP"

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

TARGET_IDS="$IDS"
if [ "$MODE" = "cross" ]; then
	echo "  driving a charge fixture on the target store..."
	TARGET_IDS="$(WP="$TARGET_WP" bash "$SELF_DIR/flow-drive.sh" "${FLOW_ARGS[@]}" --native 2>/dev/null | sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' | tr '\n' ' ')"
	if [ -z "$TARGET_IDS" ]; then
		record "target flow-drive (charge)" FAIL
	else
		record "target flow-drive (charge) -> orders: $TARGET_IDS" PASS
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

# 6. Tracks-parity (opt-in; mutates store state, so it sets up + tears down).
if [ "$WITH_TRACKS" -eq 1 ]; then
	echo "  (tracks gate: install drop-in + enable tracking on the store(s), drive, diff, restore - see HARNESS.md)"
	record "tracks parity (run via HARNESS.md recipe)" BLOCKED
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
