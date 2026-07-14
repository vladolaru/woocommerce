# MCS-02 — Shopper checkout — logged-in, selected currency · HYBRID (D + A)

Guards the logged-in variant of the converted-currency money path, whose extra contract is persistence: a logged-in customer's currency selection is stored against the user (`wcpay_currency` user meta, mirrored in session), so it must survive logout/login and new sessions — not just the current page view. The customer's saved payment methods must remain usable while shopping in the selected currency, and the resulting order must carry the same conversion meta as MCS-01. Converted charge amounts are corroborated by the implementor's `converted-currency-gate.sh`; rate freshness by `mc-rates-gate.sh` (MC-06). This row owns selection persistence + saved-PM behavior under a switched currency.

## Fixtures (both stores)

- Connected test account in test mode; default currency `USD`; `GBP` and `EUR` enabled with automatic rates cached, aligned across stores per HARNESS.md store-config discipline.
- A registered customer (same credentials on both stores) with one saved card (seed via SP-01/SC-04 fixtures); currency switcher available on the storefront.
- A simple in-stock product with a known USD price.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; switch the storefront currency to `EUR`. **Confirm prices re-render in EUR.**
2. Log out, then log back in (fresh session). **Confirm the storefront still presents EUR** — the selection persisted with the account.
3. Add the product to the cart; proceed to checkout. **Confirm totals are in EUR and the saved card is selectable** (saved-PM radio present, no forced new-card entry).
4. Pay with the saved card. **Confirm the order-received page shows the EUR total.**

End state: one logged-in order per store, paid in EUR on a saved card, with the EUR selection still active.

## Layer D — deterministic state assertion

- User meta `wcpay_currency` = `EUR` for the customer (selected-currency persistence), surviving the re-login.
- Order currency is `EUR`; meta `_wcpay_multi_currency_order_exchange_rate` set and `_wcpay_multi_currency_order_default_currency` = `USD`.
- Order is `processing`/`completed` with `_intent_id`/`_charge_id` bound to the customer's existing saved token (no new PaymentMethod created).
- Compare ref vs target persistence state, order currency, and conversion meta.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MCS-02-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
