# MO-04 — Full refund · HYBRID (A + D)

Guards the money-back path from the order edit screen. A full refund through WooPayments must move money at the provider (the confirm button must read "Refund … via WooPayments", not fall back to a bookkeeping-only manual refund), flip the order to `refunded`, record an order note with the provider refund reference, and surface the refund on the transaction's details. The regression to catch: under native the gateway refund path is missing (only "manual refund" offered — order marked refunded with no money moved), or the API refund succeeds without consistent local state.

## Fixtures (both stores)

- Connected test account.
- One paid, refundable order via WooPayments (test checkout with `4242 4242 4242 4242`; status `processing`, charge captured).

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Orders → open the paid order.
2. **A Refund button is present above/below the line items.** Click it.
3. Enter the full order amount (or restock all line quantities). **The submit button reads "Refund $&lt;total&gt; via WooPayments" — the gateway path, not only "Refund manually".**
4. Confirm the dialog. **Success: a refund line appears under the order items, the order status flips to Refunded, and an order note records the successful WooPayments refund (amount + provider refund reference).**
5. Payments → Transactions → open the charge's details: **the transaction reflects the refund (refunded state / refund entry in its timeline).**

End state: order Refunded; provider refund on record; totals net to zero.

## Layer D — deterministic state assertion

- Assert a `shop_order_refund` record exists with amount equal to the order total, and `wc_order->get_total_refunded()` equals the total.
- Assert the refund is provider-backed: a WooPayments refund id (`re_…`) is persisted (refund meta / order note), tied to the order's `_charge_id`.
- Assert order status `refunded` and the refund order note present.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MO-04-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
