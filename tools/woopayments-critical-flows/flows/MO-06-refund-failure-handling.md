# MO-06 — Refund failure handling · DETERMINISTIC

Guards the failure path of the refund pipeline. When the provider refuses a refund (amount exceeds the remaining refundable balance, or the charge is already fully refunded), WooPayments must surface the failure to the merchant AND leave local state untouched — no phantom `shop_order_refund` record surviving a failed provider refund, no inflated `get_total_refunded()`, no order-status drift. The regression to catch: native swallows the provider error and books a local refund that never happened, making the merchant believe money was returned. The contract is entirely error propagation + store-state consistency, so No browser layer is required for this flow.

## Fixtures (both stores)

- Connected test account.
- One paid order via WooPayments (`4242 4242 4242 4242`, status `processing`, captured charge), plus a forced-failure condition: fully refund the charge first (via MO-04's path or a direct provider refund), so any further refund attempt must fail.

## Layer D — deterministic state assertion

On BOTH stores:

1. Record baseline: order status, `get_total_refunded()`, count of `shop_order_refund` records.
2. Attempt a further refund through the gateway path (`$gateway->process_refund( $order_id, $amount )` via WP-CLI `eval-file`, or the order-screen AJAX equivalent) for an amount exceeding the remaining refundable balance (any amount, since the charge is fully refunded).

Assertions:

- The refund attempt fails explicitly: `process_refund()` returns `false`/`WP_Error` (not `true`), and the error message names the refund failure — the failure is surfaced, not swallowed.
- No phantom local refund: the count of `shop_order_refund` records equals the baseline (any transient refund record created by the order screen is deleted on failure), and `get_total_refunded()` is unchanged.
- Order status is unchanged from the baseline (still `refunded` from the earlier full refund — no drift to `processing` or duplicate `refunded` transitions).
- An order note records the failed refund attempt (merchant-visible failure signal), while no successful-refund note was added.
- Debug log contains the provider error but no uncaught exception/fatal.
- Compare ref vs target end-state: same failure result shape, same untouched refund totals and record counts.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MO-06-*.sh.
