# SC-11 — BNPL: Klarna / Affirm (≥$50) / Afterpay · A (+D assert)

BNPL methods hand off to provider-hosted flows and carry eligibility gates — Affirm requires a cart of at least $50; all three are currency/country gated — so the browser layer is primary. Functional acceptance: each eligible method shows, pays via its hosted test flow, and refunds cleanly; the order records `Payment via <Method>`. UX checkpoints: the BNPL group renders with correct logos, ineligible carts hide the method rather than erroring, and settings add/remove is clean.

## Fixtures (both stores)

- Connected US test account; Klarna, Affirm, and Afterpay enabled; store currency USD.
- Two simple products: one at $60.00 (all methods eligible) and one at $30.00 (below Affirm's $50 floor).
- Buyer billing country US.

## Layer A — agent-driven browser

BOTH stores:

1. Cart = $60 product; go to checkout. **Confirm Klarna, Affirm, and Afterpay all render in the BNPL group with correct logos/labels.**
2. Per method: select it; Place order → the provider-hosted test flow; approve the test payment.
3. **Functional:** redirected back to order-received; order paid; the order shows `Payment via <Method>`.
4. Admin: refund each BNPL order in full. **Confirm the refund succeeds** and the order reflects it.
5. Cart = $30 product; reload checkout. **Confirm Affirm is not offered** (below its $50 minimum) while the still-eligible methods remain.
6. Settings: disable then re-enable one BNPL method. **Confirm checkout reflects each change cleanly** (no stale entry, no error, no card fallback).

## Layer D — deterministic state assertion

- Per method: order `processing`/`completed` under the method-specific WooPayments gateway id (not the base card gateway); `_intent_id`/`_charge_id` present; amount $60 + applicable costs.
- Refund records present; totals reflect the refunds.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-11-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Coverage result — 2026-07-19

Layer A was ingested by the supervisor under run `20260719T080247Z-19380-partial` with fresh context-bound browser, state, screenshot, and exact-restoration evidence.

- Reference: `BLOCKED`. A common browser-runner failure occurred before order creation on all three hosted-provider attempts, so checkout completion, paid orders, and refunds remain inconclusive rather than product failures.
- Target: `FAIL — FUNCTIONAL`. Independent native evidence proves that the Afterpay management surface has no normal-UI setting control. The native checkout also omits all three BNPL provider logos, and native settings fragment the methods into separate generic-icon provider rows instead of the reference's integrated BNPL group.
- Both stores register Klarna, Affirm, and Afterpay at USD 60; both hide Affirm while retaining Card, Afterpay, and Klarna at USD 30. The reference UI cleanly disables and re-enables Afterpay with checkout continuity.
- Exact restoration preserved all option-row hashes and complete product/order/refund inventories. No SC-11 checkout order or refund was created.

Overall Layer A parity is `FAIL — FUNCTIONAL` on the independently demonstrated native settings gap. Layer D remains unwired, so this flow stays `PENDING` and the hosted checkout/order/refund contract must still be re-earned.
