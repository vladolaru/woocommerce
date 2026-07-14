# SC-10 — Regional methods: Bancontact / iDEAL / P24 · A (+D assert)

Stripe-redirect regional methods are currency-gated (Bancontact and iDEAL: EUR only; Przelewy24: EUR/PLN) and complete on Stripe-hosted test pages a deterministic script cannot judge, so the browser layer is primary. Functional acceptance: each method is offered only under its valid currency, pays via the hosted redirect, and refunds cleanly. UX checkpoints: correct logo/label at checkout, "Payment via <Method>" on the order, and clean add/remove of each method in settings.

## Fixtures (both stores)

- Connected test account with EU capabilities; Bancontact, iDEAL, and P24 enabled in WooPayments payment methods.
- Store currency EUR; a simple in-stock product.
- Buyer billing country matching the method: BE (Bancontact), NL (iDEAL), PL (P24).

## Layer A — agent-driven browser

BOTH stores, per method (Bancontact, iDEAL, P24):

1. With currency EUR and the matching billing country, go to checkout. **Confirm the method renders with its correct logo and label.**
2. Select it; Place order → the Stripe-hosted test page. Click **Authorize** the test payment.
3. **Functional:** redirected back to order-received; order paid; the order/confirmation shows **"Payment via <Method>"**.
4. Admin: refund the order in full. **Confirm the refund succeeds** and the order reflects it.
5. Switch store currency to USD; reload checkout. **Confirm the method is not offered.** Switch back to EUR.
6. Settings: disable the method → gone from checkout; re-enable → offered again. **Add/remove is clean** (no stale entry, no error, card method unaffected).

## Layer D — deterministic state assertion

- Per method: order `processing`/`completed` under the method-specific WooPayments gateway id (not the base card gateway); `_intent_id`/`_charge_id` present; currency EUR.
- Refund record exists per order; status/total reflect the full refund.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-10-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
