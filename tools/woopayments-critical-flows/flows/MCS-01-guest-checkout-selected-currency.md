# MCS-01 — Shopper checkout — guest, selected currency · HYBRID (D + A)

Guards the guest money path in a non-default currency: a guest who switches to GBP must see converted prices everywhere (shop → cart → checkout), pay converted totals, and end up with an order stored in GBP carrying truthful conversion meta. Currency-gated payment methods must follow the switch (card stays available; any method invalid for GBP must not render). This is the flow the implementor's `converted-currency-gate.sh` corroborates deterministically — it drives real converted GBP charges on both stores and reconciles amounts against Stripe — while this row adds the shopper-visible switcher/pricing UX and the order-meta contract.

## Fixtures (both stores)

- Connected test account in test mode; default currency `USD`; `GBP` and `EUR` enabled with automatic rates cached, aligned across stores per HARNESS.md store-config discipline.
- Currency switcher available on the storefront (widget or block, per MC-03/MC-04).
- A simple in-stock product with a known USD price (e.g. `test-lab-beaker-001`); guest checkout enabled; card `4242 4242 4242 4242`.

## Layer A — agent-driven browser

BOTH stores (fresh guest session, no login):

1. On the shop page, use the **currency switcher** to select `GBP`. **Confirm product prices re-render in GBP** consistent with the cached rate.
2. Add the product to the cart. **Confirm cart line, subtotal, and total are in GBP.**
3. Proceed to checkout. **Confirm the order total is the converted GBP amount** and **the WooPayments card method renders** (no GBP-invalid methods shown).
4. Pay with `4242 4242 4242 4242`. **Confirm the order-received page shows the GBP total.**

End state: one guest order per store, placed and paid in GBP.

## Layer D — deterministic state assertion

- Order currency is `GBP`; order total equals the USD price converted at the cached rate (rounding/charm applied).
- Order meta `_wcpay_multi_currency_order_exchange_rate` set to the rate used and `_wcpay_multi_currency_order_default_currency` = `USD`; `_wcpay_multi_currency_stripe_exchange_rate` present when the account provides it.
- Order is `processing`/`completed` with `_intent_id`/`_charge_id`; charged amount/currency match the GBP total (cross-check: `converted-currency-gate.sh` reconciliation).
- Compare ref vs target order currency, totals, and conversion meta.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MCS-01-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
