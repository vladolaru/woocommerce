# SS-06 — Cancel + re-subscribe · HYBRID (D + A)

Guards the subscription exit-and-return loop: the shopper must be able to cancel from My Account (Cancel control → subscription stops renewing) and then re-subscribe, producing a fresh, independently-billable subscription. A native build where the Cancel control is missing, cancellation leaves the renewal schedule live, or the re-subscribe purchase fails to create a new active subscription with its own token binding is a regression.

## Fixtures (both stores)

- WC Subscriptions active; connected test account; saved cards enabled.
- One active subscription paid via WooPayments (seed via SS-01) for a $9.99/month product.

## Layer A — agent-driven browser

BOTH stores:

1. Log in as the customer; My Account → Subscriptions → open the subscription.
2. **The Cancel control is present**; click it (confirm if prompted).
3. **The subscription status updates** to Cancelled (or Pending cancellation until the prepaid term ends) with a confirmation notice.
4. **A re-subscribe path is available:** the Resubscribe button on the cancelled subscription, or purchasing the product again from the shop.
5. Complete the re-subscribe checkout with card `4242 4242 4242 4242` — **mandate copy renders, order completes**.
6. **Functional end-state:** My Account → Subscriptions lists the old subscription as Cancelled and a NEW subscription as Active with its own next-payment date.

## Layer D — deterministic state assertion

- Assert the original subscription is `cancelled` (or `pending-cancel` with an end date and NO future `next_payment` renewal action scheduled).
- Assert a second subscription exists, status `active`, with its own parent/resubscribe order in `processing`/`completed` carrying `_intent_id`/`_charge_id`.
- Assert the new subscription has valid payment meta (`_payment_method_id` family pointing at a provider-backed token) so its renewals can charge.
- Assert no renewal action remains queued against the cancelled subscription.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-06-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
