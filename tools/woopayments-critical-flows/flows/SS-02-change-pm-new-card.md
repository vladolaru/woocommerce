# SS-02 — Change subscription payment method to a NEW card · HYBRID (D + A)

Guards the shopper-side change-payment flow with a fresh card (the new-card sibling of SS-03's saved-card path). The subscription view must expose a "Change payment" affordance, accept a new card, persist the resulting token on the subscription, update the payment-method row, and renew against the new card. A native build where the affordance is missing, the new token is saved but not bound to the subscription, or the next renewal still charges the old card is a regression.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- One active subscription paid via WooPayments on card `4242…4242` (seed via SS-01).

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → Subscriptions → open the subscription.
2. **The "Change payment" affordance is present** on the subscription view.
3. Click it; on the change-payment form enter a NEW card: `5555 5555 5555 4444`, `12/34`, `123`; submit.
4. **Success feedback renders** (e.g. "Payment method updated.").
5. **The subscription's payment-method row updates** to the new card (Mastercard ending `4444`) and persists across a reload.
6. **Functional end-state:** the subscription remains Active with its schedule untouched; the new card also appears under My Account → Payment methods.

## Layer D — deterministic state assertion

After the browser change + a driven renewal:

- Assert the subscription's payment meta (`_payment_method_id` family) points at the NEW token (last-4 `4444`), not the original `4242` token.
- Drive a renewal (`WC_Subscriptions_Manager::process_renewal` or the scheduled action) and assert the renewal order is `processing`/`completed` with `_intent_id`/`_charge_id` present.
- Assert the renewal charged the NEW card's PaymentMethod (charge PM/fingerprint matches the `4444` token, not the old card).
- Assert `next_payment` advanced by one billing period after the renewal.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-02-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
