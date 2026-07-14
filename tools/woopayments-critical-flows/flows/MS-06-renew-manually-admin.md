# MS-06 — Renew manually (admin) · HYBRID (A + D)

Guards the merchant's ability to force a renewal on demand from the subscription edit screen. The regression to catch: under native the "Process renewal" order action disappears or runs without charging (renewal order created but never paid via WooPayments), or the charge succeeds but the billing schedule doesn't advance — setting up a double charge when the scheduled renewal fires anyway.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- One active subscription paid via WooPayments with a saved token (seed via SS-01), next payment date in the future.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Subscriptions → open the seeded subscription (Edit).
2. Open the Subscription actions (order actions) dropdown. **"Process renewal" is offered.**
3. Select "Process renewal" and click Update. **No error notice; the page reloads on the subscription.**
4. **A new renewal order appears in the Related Orders box, marked as Renewal Order, with a paid status (Processing/Completed).**
5. Open that renewal order. **An order note records a successful WooPayments charge (amount + intent reference), not a pending/failed payment.**
6. Back on the subscription: **Billing Schedule shows the next payment date advanced and status remains Active.**

End state: one paid renewal order related to the subscription; subscription active with an advanced schedule.

## Layer D — deterministic state assertion

After the admin-triggered renewal:

- Assert the newest related renewal order is `processing`/`completed` with `_intent_id` and `_charge_id`, amount equal to the subscription total.
- Assert the charge used the subscription's saved token (no new PaymentMethod created on the customer).
- Assert subscription status `active` and `_schedule_next_payment` advanced beyond its pre-renewal value.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-06-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
