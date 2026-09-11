# SC-10 — Regional methods: Bancontact / iDEAL / P24 · A (+D assert)

Stripe-redirect regional methods are currency-gated (Bancontact and iDEAL: EUR only; Przelewy24: EUR/PLN) and complete on Stripe-hosted test pages a deterministic script cannot judge, so the browser layer is primary. Functional acceptance: each method is offered only under its valid currency, pays via the hosted redirect, and refunds cleanly. UX checkpoints: correct logo/label at checkout, "Payment via `<Method>`" on the order, and clean add/remove of each method in settings.

## Fixtures (both stores)

- Connected test account with EU capabilities; Bancontact, iDEAL, and P24 enabled in WooPayments payment methods.
- Store currency EUR; a simple in-stock product.
- Buyer billing country matching the method: BE (Bancontact), NL (iDEAL), PL (P24).

## Layer A — agent-driven browser

BOTH stores, per method (Bancontact, iDEAL, P24):

1. With currency EUR and the matching billing country, go to checkout. **Confirm the method renders with its correct logo and label.**
2. Select it; Place order → the Stripe-hosted test page. Click **Authorize** the test payment.
3. **Functional:** redirected back to order-received; order paid; the order/confirmation shows **"Payment via `<Method>`"**.
4. Admin: refund the order in full. **Confirm the refund succeeds** and the order reflects it.
5. Switch store currency to USD; reload checkout. **Confirm the method is not offered.** Switch back to EUR.
6. Settings: disable the method → gone from checkout; re-enable → offered again. **Add/remove is clean** (no stale entry, no error, card method unaffected).

## Layer D — deterministic state assertion

- Per method: order `processing`/`completed` under the method-specific WooPayments gateway id (not the base card gateway); `_intent_id`/`_charge_id` present; currency EUR.
- Refund record exists per order; status/total reflect the full refund.
- Compare ref vs target end-state.

## Latest runner-verified production evidence (2026-07-19)

- Direct Playwright, context-bound run `20260719T093748Z-12570-partial` records overall `FAIL — FUNCTIONAL`.
- Reference: iDEAL and Bancontact rendered with provider labels/logos, completed the hosted redirect without an explicit Action Scheduler drain, produced correctly bound EUR orders, and completed full provider refunds through one trusted admin action each. USD hid both methods and retained card.
- Native: ordinary EUR checkout exposed card only despite active iDEAL/Bancontact capabilities and enabled settings. A broader fixture made each split gateway selectable, but both submissions failed before payment because `getClassicCheckoutAppearance()` loaded without its required appearance utility.
- Settings architecture also differs: the reference groups regional methods as integrated WooPayments checkboxes with provider icons, while native exposes separate generic-icon WooPayments provider rows.
- P24 remained truthfully blocked because its real capability is `unrequested`; it was never enabled or fabricated. The disable/re-enable settings lifecycle was not driven in this slice.
- Both stores restored the exact five mutable option rows and preserved every pre-existing product, order, and refund.

The context-bound evidence producer and validators live under `evidence/coverage-production/SC-10/`. A general Layer-D flow exerciser remains not yet wired.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
