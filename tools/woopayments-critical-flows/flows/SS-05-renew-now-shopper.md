# SS-05 — Renew now (manual early renewal, shopper) · HYBRID (D + A)

Guards shopper-initiated manual renewal: with early renewals enabled, the subscription view must expose a "Renew now" affordance that produces a paid renewal order on the saved card and advances the next-payment date by one billing period. A native build where the button is missing, the renewal order is created but unpaid, or the date fails to advance (double-billing the shopper at the original date) is a regression.

## Fixtures (both stores)

- WC Subscriptions active with **early renewals enabled** (WooCommerce → Settings → Subscriptions → "Accept Early Renewal Payments").
- Connected test account; one active subscription paid via WooPayments with a saved card (seed via SS-01). Record its `next_payment` date before the run.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → Subscriptions → open the subscription.
2. **The "Renew now" affordance is present** on the subscription view.
3. Click it and complete the early-renewal checkout using the **saved card** (it must be offered — no forced new-card entry).
4. **A success/result signal renders** (order-received page or renewal-complete notice).
5. **Functional end-state:** the subscription view shows a NEW renewal order in Related orders and the displayed next-payment date has moved one billing period later than the pre-run date.

## Layer D — deterministic state assertion

- Assert a new renewal order exists for the subscription, status `processing`/`completed`, with `_intent_id` and `_charge_id` present and the correct recurring amount/currency.
- Assert the renewal charged the subscription's saved token (charge PM matches the token's `pm_…` ID — no new PaymentMethod created).
- Assert the subscription remains `active` and `next_payment` = pre-run date + 1 billing period.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-05-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
