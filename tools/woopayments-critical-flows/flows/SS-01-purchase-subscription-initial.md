# SS-01 — Purchase subscription (initial) · HYBRID (D + A)

Guards the initial subscription purchase — the seed flow every other SS row builds on. Checkout with a subscription in the cart must charge the initial order, create an active subscription with the correct next-payment date, and force-save the card as a reusable token. Two mandate-sensitive UI behaviors are load-bearing: the future-payments **mandate copy** must render under the card fields ("…you allow \<store\> to charge your card for future payments in accordance with their terms"), and the **save-payment-information checkbox must NOT render** (saving is implicit for subscriptions, not optional). A native build that drops the mandate, shows an ignorable save checkbox, or pays without persisting a token breaks every renewal downstream.

## Fixtures (both stores)

- WC Subscriptions active; connected test account; saved cards enabled.
- A simple subscription product: **$9.99 / month**, no sign-up fee, no trial.
- A registered customer (same credentials on both stores) with no saved payment methods.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; add the subscription product to the cart; go to checkout.
2. **Recurring totals render** ("$9.99 / month" and first-renewal context) in the order summary.
3. Select the card method; enter `4242 4242 4242 4242`, `12/34`, `123`.
4. **Mandate copy renders below the card fields** (future-payments authorization text naming the store).
5. **NO "Save payment information to my account" checkbox is shown** for this subscription purchase.
6. Place order → **order-received page confirms the purchase**.
7. **Functional end-state:** My Account → Subscriptions lists the new subscription as Active with next payment one month out; My Account → Payment methods lists the card ending `4242`.

## Layer D — deterministic state assertion

- Assert the parent order is `processing`/`completed` with `_intent_id` and `_charge_id`, amount $9.99, store currency.
- Assert one subscription exists, status `active`, `next_payment` ≈ purchase date + 1 month, linked to the parent order.
- Assert a WooPayments token (last-4 `4242`, provider `pm_…` meta) was saved for the customer and is attached to the subscription's payment meta (`_payment_method_id` family).
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-01-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
