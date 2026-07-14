# SC-11 — BNPL: Klarna / Affirm (≥$50) / Afterpay · A (+D assert)

BNPL methods hand off to provider-hosted flows and carry eligibility gates — Affirm requires a cart of at least $50; all three are currency/country gated — so the browser layer is primary. Functional acceptance: each eligible method shows, pays via its hosted test flow, and refunds cleanly; the order records "Payment via <Method>". UX checkpoints: the BNPL group renders with correct logos, ineligible carts hide the method rather than erroring, and settings add/remove is clean.

## Fixtures (both stores)

- Connected US test account; Klarna, Affirm, and Afterpay enabled; store currency USD.
- Two simple products: one at $60.00 (all methods eligible) and one at $30.00 (below Affirm's $50 floor).
- Buyer billing country US.

## Layer A — agent-driven browser

BOTH stores:

1. Cart = $60 product; go to checkout. **Confirm Klarna, Affirm, and Afterpay all render in the BNPL group with correct logos/labels.**
2. Per method: select it; Place order → the provider-hosted test flow; approve the test payment.
3. **Functional:** redirected back to order-received; order paid; the order shows **"Payment via <Method>"**.
4. Admin: refund each BNPL order in full. **Confirm the refund succeeds** and the order reflects it.
5. Cart = $30 product; reload checkout. **Confirm Affirm is not offered** (below its $50 minimum) while the still-eligible methods remain.
6. Settings: disable then re-enable one BNPL method. **Confirm checkout reflects each change cleanly** (no stale entry, no error, no card fallback).

## Layer D — deterministic state assertion

- Per method: order `processing`/`completed` under the method-specific WooPayments gateway id (not the base card gateway); `_intent_id`/`_charge_id` present; amount $60 + applicable costs.
- Refund records present; totals reflect the refunds.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-11-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
