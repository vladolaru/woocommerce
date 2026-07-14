#!/usr/bin/env bash
#
# Financial reconciliation harness (WooPayments → core merge, A0, design-spec §6.3 R12).
#
# Reconciles WC-side money records against the provider's raw source: charge amount/currency,
# capture state, refunds, fee/net metadata, dispute and payout linkage, and multi-currency
# exchange-rate metadata when present. This is the money-safety oracle — RULE 0 on the money
# path. It NEVER trusts WC's own copy alone; it reads the raw provider record via the Stripe CLI
# (the only allowed raw-source reader; never the remote WPCOM sandbox).
#
#   WP="docker exec -i wcpay_wp_default wp --allow-root" financial-reconcile.sh <order_id>...
#
# PRECONDITIONS (this harness fails closed if they are not met — it will not emit a false PASS):
#   1. A valid Stripe CLI session on the host: `stripe login` (the local test key expires; refresh
#      when stale). NOTE: the legacy Transact container runs its own valid in-container key for the
#      listener; this reconciler reads raw source from the HOST Stripe CLI, which is separate.
#   2. The event listener running so provider events reach the store (the operator runs these):
#        - legacy Transact env (~/Work/a8c/transact-platform-server) — the reference store at :8082
#          uses this: `npm run stripe listen`   <-- the relevant one for the reference oracle
#        - wpcom-local env (only if a store is routed to http://wpcom.localhost:30001): `transact listen`
#      Drive operations (refunds/disputes/payouts) via the dev-tools Test Lab, e.g.:
#        wp wcpay-dev test-lab refunds ...   (then re-run this reconciler on the affected order)

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	echo "FAIL: local runner safety library is missing: $LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"
WP="${WP:-wp}"
IDS=("$@")
COMPARE="$SELF_DIR/financial-reconcile-normalize.py"

if [ "${#IDS[@]}" -eq 0 ]; then
	echo "usage: WP='<wp runner>' financial-reconcile.sh <order_id>..." >&2
	exit 2
fi

# Local-only enforcement (RULE: never read/mutate store money state on a remote store).
if ! runner_error="$(woopayments_validate_local_wp_runner "$WP")"; then
	echo "FAIL: unsafe WP-CLI command for \$WP: $runner_error" >&2
	exit 2
fi

if ! command -v python3 >/dev/null 2>&1; then
	echo "BLOCKED: python3 is required for structured financial reconciliation." >&2
	exit 3
fi
if [ ! -f "$COMPARE" ]; then
	echo "BLOCKED: missing comparator $COMPARE." >&2
	exit 3
fi

# --- Preflight: refuse to run (and refuse to imply success) without raw-source access. ---
echo "Preflight:"
probe="$(stripe charges list --limit 1 --color off 2>&1)"
probe_rc=$?
if [ "$probe_rc" -ne 0 ]; then
	echo "  BLOCKED: Stripe CLI raw-source read unavailable:"
	printf '%s\n' "$probe" | sed 's/^/    /' | head -3
	echo
	echo "  Reconciliation cannot verify money against source. Restore Stripe CLI connectivity and retry."
	echo "  (Refusing to emit a PASS from WC-side data alone — that would defeat the oracle.)"
	exit 3
fi
if printf '%s' "$probe" | grep -qi "expired\|no api key\|stripe login\|Invalid API Key"; then
	echo "  BLOCKED: Stripe CLI has no valid session (raw-source read unavailable):"
	printf '%s\n' "$probe" | sed 's/^/    /' | head -3
	echo
	echo "  Reconciliation cannot verify money against source. Run 'stripe login' and retry."
	echo "  (Refusing to emit a PASS from WC-side data alone — that would defeat the oracle.)"
	exit 3
fi
echo "  ok: Stripe CLI session valid."

# WooPayments charges live on the store's CONNECTED account, not the platform account, so all
# raw-source reads must carry --stripe-account. Auto-detect it from the store.
ACCT="$($WP eval '
if ( class_exists( "WC_Payments" ) && method_exists( "WC_Payments", "get_account_service" ) ) {
	echo WC_Payments::get_account_service()->get_stripe_account_id();
	return;
}

if ( function_exists( "wc_get_container" ) && class_exists( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class ) ) {
	echo wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class )->get_account_id();
	return;
}
' 2>/dev/null | tr -d "[:space:]")"
if [ -z "$ACCT" ]; then
	echo "  BLOCKED: could not resolve the connected Stripe account from the store." >&2
	exit 3
fi
echo "  ok: connected account $ACCT."

TMP_BASE="${TMPDIR:-$SELF_DIR/.tmp}"
mkdir -p "$TMP_BASE"
RUN_DIR="$(mktemp -d "$TMP_BASE/financial-reconcile.XXXXXX")"
trap 'rm -rf "$RUN_DIR"' EXIT

json_get() {
	python3 - "$1" "$2" <<'PY'
import json, sys
path, key_path = sys.argv[1], sys.argv[2]
try:
    data = json.load(open(path))
except Exception:
    sys.exit(0)
value = data
for key in key_path.split("."):
    if isinstance(value, dict) and key in value:
        value = value[key]
    else:
        value = ""
        break
if isinstance(value, dict):
    value = value.get("id", "")
if isinstance(value, bool):
    print("true" if value else "false")
elif value is not None:
    print(value)
PY
}

json_object_to_file() {
	python3 - "$1" "$2" "$3" <<'PY'
import json, sys
source, key_path, destination = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    data = json.load(open(source))
except Exception:
    sys.exit(1)
value = data
for key in key_path.split("."):
    if isinstance(value, dict) and key in value:
        value = value[key]
    else:
        sys.exit(1)
if not isinstance(value, dict):
    sys.exit(1)
with open(destination, "w") as handle:
    json.dump(value, handle)
    handle.write("\n")
PY
}

stripe_to_file() {
	local label="$1"
	local outfile="$2"
	shift 2
	local raw rc
	raw="$("$@" --stripe-account "$ACCT" --color off 2>&1)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		if [ "$label" = "charge" ] && printf '%s' "$raw" | grep -qi "No such charge"; then
			return 11
		fi
		echo "  $label: raw provider read failed — BLOCKED."
		printf '%s\n' "$raw" | sed 's/^/    /' | head -3
		echo
		echo "  Reconciliation cannot verify money against source. Restore Stripe CLI connectivity and retry."
		exit 3
	fi
	printf '%s\n' "$raw" > "$outfile"
}

# --- Reconcile each order: WC-side money state vs Stripe raw source for its charge. ---
fail=0
reconciled=0
skipped=0
skipped_ids=""
for order_id in "${IDS[@]}"; do
	order_dir="$RUN_DIR/order-$order_id"
	mkdir -p "$order_dir"
	wc_file="$order_dir/wc.json"
	charge_file="$order_dir/charge.json"
	intent_file="$order_dir/intent.json"
	balance_file="$order_dir/balance.json"
	refunds_file="$order_dir/refunds.json"
	disputes_file="$order_dir/disputes.json"
	payout_file="$order_dir/payout.json"

	# WC side: persisted order/refund money state as structured JSON.
	if ! $WP eval-file - "$order_id" > "$wc_file" <<'PHP' 2>/dev/null
<?php
$order = wc_get_order( (int) $args[0] );
if ( ! $order ) {
	WP_CLI::line( wp_json_encode( array( 'error' => 'not_found' ) ) );
	return;
}

$all_meta = array();
foreach ( $order->get_meta_data() as $meta ) {
	$all_meta[ (string) $meta->key ] = is_scalar( $meta->value ) ? (string) $meta->value : wp_json_encode( $meta->value );
}

$find_meta_like = function( array $needles ) use ( $all_meta ): string {
	foreach ( $all_meta as $key => $value ) {
		foreach ( $needles as $needle ) {
			if ( false !== stripos( $key, $needle ) && '' !== (string) $value ) {
				return (string) $value;
			}
		}
	}
	return '';
};

$find_provider_id_meta_like = function( array $needles, array $prefixes ) use ( $all_meta ): string {
	$prefixes = array_map(
		static function( $prefix ): string {
			return strtolower( (string) $prefix ) . '_';
		},
		$prefixes
	);

	foreach ( $all_meta as $key => $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			continue;
		}

		foreach ( $needles as $needle ) {
			if ( false === stripos( $key, $needle ) ) {
				continue;
			}

			$value_lower = strtolower( $value );
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $value_lower, $prefix ) ) {
					return $value;
				}
			}
		}
	}

	return '';
};

$charge_id = $order->get_meta( '_charge_id' );
if ( '' === $charge_id && str_starts_with( (string) $order->get_transaction_id(), 'ch_' ) ) {
	$charge_id = $order->get_transaction_id();
}

$intent_id = $order->get_meta( '_intent_id' );
if ( '' === $intent_id && str_starts_with( (string) $order->get_transaction_id(), 'pi_' ) ) {
	$intent_id = $order->get_transaction_id();
}

$refunds = array();
foreach ( $order->get_refunds() as $r ) {
	$refunds[] = array(
		'id'                         => (int) $r->get_id(),
		'amount'                     => (string) $r->get_amount(),
		'currency'                   => strtolower( (string) $r->get_currency() ),
		'provider_refund_id'         => (string) $r->get_meta( '_wcpay_refund_id', true ),
		'provider_refund_txn_id'     => (string) $r->get_meta( '_wcpay_refund_transaction_id', true ),
		'provider_refund_status'     => (string) $r->get_meta( '_wcpay_refund_status', true ),
	);
}

$notes = array();
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
	$date_created = '';
	if ( isset( $note->date_created ) && $note->date_created instanceof WC_DateTime ) {
		$date_created = $note->date_created->date( 'c' );
	} elseif ( isset( $note->date_created ) ) {
		$date_created = (string) $note->date_created;
	}

	$notes[] = array(
		'id'            => isset( $note->id ) ? (int) $note->id : 0,
		'content'       => isset( $note->content ) ? trim( (string) $note->content ) : '',
		'added_by'      => isset( $note->added_by ) ? (string) $note->added_by : '',
		'customer_note' => ! empty( $note->customer_note ),
		'date_created'  => $date_created,
	);
}

WP_CLI::line(
	wp_json_encode(
		array(
			'order_id'                 => (int) $order->get_id(),
				'status'                   => (string) $order->get_status(),
				'total'                    => (string) $order->get_total(),
				'currency'                 => strtoupper( (string) $order->get_currency() ),
				'store_currency'           => strtoupper( (string) get_option( 'woocommerce_currency' ) ),
				'transaction_id'           => (string) $order->get_transaction_id(),
			'charge_id'                => (string) $charge_id,
			'intent_id'                => (string) $intent_id,
			'payment_transaction_id'   => (string) $order->get_meta( '_wcpay_payment_transaction_id', true ),
			'transaction_fee'          => (string) $order->get_meta( '_wcpay_transaction_fee', true ),
			'net'                      => (string) $order->get_meta( '_wcpay_net', true ),
			'intent_currency'          => (string) $order->get_meta( '_wcpay_intent_currency', true ),
			'multi_currency'           => array(
				'stripe_exchange_rate' => (string) $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ),
				'order_exchange_rate'  => (string) $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ),
				'order_default_currency' => (string) $order->get_meta( '_wcpay_multi_currency_order_default_currency', true ),
			),
			'refunds'                  => $refunds,
			'notes'                    => $notes,
			'dispute_id'               => $find_provider_id_meta_like( array( 'dispute' ), array( 'du', 'dp' ) ),
			'payout_id'                => $find_meta_like( array( 'payout' ) ),
			'meta'                     => $all_meta,
		)
	)
);
PHP
	then
		echo "  order $order_id: WC-side money state read failed — BLOCKED."
		exit 3
	fi

	charge_id="$(json_get "$wc_file" charge_id)"
	if [ -z "$charge_id" ]; then
		echo "  order $order_id: no charge id on WC side — cannot reconcile against the provider."
		skipped=$((skipped + 1))
		skipped_ids="$skipped_ids $order_id"
		continue
	fi
	# Defensive: a charge id is always [A-Za-z0-9_]; reject anything else before it reaches the CLI
	# (blocks a stray meta value being interpreted as a flag/argument). Fail-closed: can't verify => fail.
	if ! printf '%s' "$charge_id" | grep -qE '^[A-Za-z0-9_]+$'; then
		echo "  order $order_id: charge id has unexpected characters — refusing to query the provider. RECONCILE FAIL."
		fail=1
		continue
	fi

	if ! stripe_to_file "charge" "$charge_file" stripe charges retrieve "$charge_id" -e balance_transaction -e refunds; then
		echo "  order $order_id: charge $charge_id not found at provider — RECONCILE FAIL."
		fail=1
		continue
	fi

	intent_id="$(json_get "$wc_file" intent_id)"
	if [ -z "$intent_id" ]; then
		intent_id="$(json_get "$charge_file" payment_intent)"
	fi
	if printf '%s' "$intent_id" | grep -qE '^pi_[A-Za-z0-9_]+$'; then
		stripe_to_file "payment intent" "$intent_file" stripe payment_intents retrieve "$intent_id" -e latest_charge
	else
		printf '{}\n' > "$intent_file"
	fi

	if json_object_to_file "$charge_file" balance_transaction "$balance_file"; then
		:
	else
		balance_id="$(json_get "$charge_file" balance_transaction)"
		if printf '%s' "$balance_id" | grep -qE '^txn_[A-Za-z0-9_]+$'; then
			stripe_to_file "balance transaction" "$balance_file" stripe balance_transactions retrieve "$balance_id"
		else
			printf '{}\n' > "$balance_file"
		fi
	fi

	stripe_to_file "refunds" "$refunds_file" stripe refunds list --charge "$charge_id" --limit 100
	stripe_to_file "disputes" "$disputes_file" stripe disputes list --charge "$charge_id" --limit 100

	payout_id="$(json_get "$balance_file" payout)"
	if printf '%s' "$payout_id" | grep -qE '^po_[A-Za-z0-9_]+$'; then
		stripe_to_file "payout" "$payout_file" stripe payouts retrieve "$payout_id"
	else
		printf '{}\n' > "$payout_file"
	fi

	if python3 "$COMPARE" --wc "$wc_file" --charge "$charge_file" --intent "$intent_file" --balance-transaction "$balance_file" --refunds "$refunds_file" --disputes "$disputes_file" --payout "$payout_file"; then
		echo "  order $order_id (charge $charge_id): widened money matrix matches provider. ok"
		reconciled=$((reconciled + 1))
	else
		rc=$?
		if [ "$rc" -eq 3 ]; then
			echo "  order $order_id (charge $charge_id): widened money matrix could not be fully verified — BLOCKED."
			exit 3
		fi
		echo "  order $order_id (charge $charge_id): widened money matrix mismatch — RECONCILE FAIL."
		fail=1
	fi
done

echo
if [ "$fail" -ne 0 ]; then
	echo "FAIL: financial reconciliation matrix found WC↔provider divergence (RULE 0 money-path)."
	exit 1
fi
# Fail-closed on vacuous runs: a PASS may only rest on orders that were positively
# reconciled against the provider's raw source. An order with no charge id (missing
# order, or a native run that failed to persist _charge_id — itself a RULE 0 meta
# regression) is unverified, and unverified must never count green.
if [ "$reconciled" -eq 0 ] || [ "$skipped" -gt 0 ]; then
	echo "BLOCKED: reconciled $reconciled order(s); $skipped order(s) had no provider charge to verify (ids:${skipped_ids:- none})."
	echo "  Nothing unverified may count green. Supply provider-charged order ids, or investigate why these orders lack a charge id."
	exit 3
fi
echo "PASS: WC-side money records match the provider's raw source for all $reconciled reconciled order(s) across the widened matrix."
exit 0
