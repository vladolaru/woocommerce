# MCS-02 — Shopper checkout — logged-in, selected currency · HYBRID (D + A)

Guards the logged-in variant of the converted-currency money path, whose extra contract is persistence: a logged-in customer's currency selection is stored against the user (`wcpay_currency` user meta, mirrored in session), so it must survive logout/login and new sessions — not just the current page view. The customer's saved payment methods must remain usable while shopping in the selected currency, and the resulting order must carry the same conversion meta as MCS-01. Converted charge amounts are corroborated by the implementor's `converted-currency-gate.sh`; rate freshness by `mc-rates-gate.sh` (MC-06). This row owns selection persistence + saved-PM behavior under a switched currency.

## Fixtures (both stores)

- Connected test account in test mode; default currency `USD`; `GBP` and `EUR` enabled with automatic rates cached, aligned across stores per HARNESS.md store-config discipline.
- A registered customer (same credentials on both stores) with a saved Visa ending 4242 plus a secondary saved card (seed via SP-01/SC-04 fixtures); currency switcher available on the storefront.
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

## Runner-verified evidence (2026-07-19)

Layer A earned reference `PASS`, target `PASS`, and parity `PASS` in archived run `20260719T062008Z-16096-partial`. The supervisor accepted the exact stamped result at SHA-256 `b58987faee5051b1e85a23b9bc7ddfca16a2b2ab89b3c5c368305edce50714d0`.

- Both customers selected EUR, saw the aligned product at EUR 18, logged out, lost only store-scoped cookies, opened a new page, logged in again, and retained EUR.
- Cart and checkout independently showed EUR 18 for the line, subtotal, and shipping, with an EUR 36 total. The Store API projections matched those browser values.
- Each checkout selected the customer's pre-existing Visa ending 4242. The resulting EUR 36 orders retained default-currency USD and exchange-rate `0.88` metadata, and the provider intents/charges succeeded for EUR 3,600 minor units against the same pre-existing PaymentMethods.
- Complete provider PaymentMethod inventories were byte-for-byte stable before and after checkout, so neither store created a replacement method.
- The exact pre-run `wcpay_currency` row shape and shared multi-currency option snapshots were restored afterward. The paid evidence orders and provider objects were retained.

The target's raw option-boundary captures also reproduce the native base-currency/cache lifecycle defect owned by MCS-01: the shopper request temporarily changes the tracked store currency and rate-cache row. It does not break this flow's logged-in persistence or saved-payment contract, so it is recorded as an architectural diagnostic rather than misclassified as an MCS-02 failure. Layer D remains not yet wired.
