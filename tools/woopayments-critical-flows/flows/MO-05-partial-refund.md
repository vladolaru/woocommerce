# MO-05 — Partial refund (one, then several) · HYBRID (A + D)

Guards incremental refunds. A merchant must be able to refund a single line item, then refund again later, with each partial producing its own provider refund and the cumulative refunded total tracked correctly. The regression to catch: under native the per-line refund inputs don't compute the amount, a second partial is rejected or double-counts, the running "available to refund" figure lies, or the order flips to `refunded` while money remains uncaptured-back at the provider (or vice versa).

## Fixtures (both stores)

- Connected test account.
- One paid order via WooPayments containing at least two line items with distinct prices (e.g. `$15` + `$10`), status `processing`.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Orders → open the paid order → Refund.
2. Refund the first line item only (qty 1, `$15`). **Amount fields auto-compute; button reads "Refund $15.00 via WooPayments".** Confirm.
3. **Success: a refund row for -$15.00 appears; order status stays Processing (not Refunded); the order totals show Refunded -$15.00 and a reduced net payment.**
4. Click Refund again. **The available-to-refund amount now excludes the first partial.** Refund `$5.00` of the second item. Confirm.
5. **A second, separate refund row (-$5.00) appears; cumulative refunded shows $20.00; status still Processing (a remainder is unrefunded).**
6. Payments → Transactions → charge details: **the transaction shows a partially-refunded state reflecting both refunds.**

End state: two partial refunds on record, cumulative `$20.00`, order not fully refunded.

## Layer D — deterministic state assertion

- Assert two distinct `shop_order_refund` records with amounts `$15.00` and `$5.00`, each carrying its own provider refund id (`re_…`) bound to the order's `_charge_id` (two provider refunds, not one mutated one).
- Assert `get_total_refunded()` equals `$20.00` and order status remains `processing` while the remainder is unrefunded.
- Assert both refund order notes present with amounts.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MO-05-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
