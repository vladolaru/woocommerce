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

## Runner-verified outcome — 2026-07-19

Status remains `PENDING`: the supervisor accepted the evidence package, but the target earned `FAIL - functional` rather than parity.

- Runner archive: `tools/woopayments-critical-flows/evidence/runs/20260719T050622Z-75094-partial`
- Accepted result: `tools/woopayments-critical-flows/evidence/coverage-production/MCS-01/agent-results/MCS-01-guest-checkout-selected-currency.json`
- Accepted result SHA-256: `sha256:0b25ba900656177f2dcc03d1b3ac6588326948e90091f2e682791664e3aa390f`
- Result content SHA-256: `sha256:8a45492c560da6ec330547afefea7835d282019617b25bd3c516dab85834bdb9`
- Reference: `PASS`. A fresh guest selected GBP, saw `£15.00` product/subtotal/shipping and `£30.00` total through checkout, paid with WooPayments, and received guest order `2201`. The order and Stripe intent/charge independently reconcile to GBP `30.00`, default currency `USD`, and exchange rate `0.75`.
- Target: `FAIL - functional`. Shop, product, and cart remained GBP, but checkout rendered `$15.00` product/subtotal/delivery and `$30.00` total while the Currency control remained on GBP. The driver stopped before card entry or checkout submission, so no target order or provider object was created.
- Classification: new native regression against the established WooPayments reference. The same GBP request also changed the native tracked store currency from USD to GBP and rewrote the automatic-rate cache; the reference kept its raw store-currency lifecycle unchanged.
- Restoration proof: only the 23 allow-listed multi-currency option rows were restored. Reference returned to `sha256:22a5914d0d684f321804b269978c5f11182dab3ebca36fff58d137e9813fbb64`; target returned to `sha256:c2384c3691fad9e681bf9a9feb8747ca08523620559f2569ee7aaf781a22d118`. The legitimate reference order was retained, and the target order inventory remained unchanged.

Re-earn this row by keeping the merchant base/store currency and shared rate cache independent from a guest's selected GBP currency, preserving GBP through Blocks checkout, completing the WooPayments payment, and reproducing the reference-compatible order metadata and provider amount/currency joins in a fresh dual-store supervisor run.
