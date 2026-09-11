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

## Latest runner evidence

Layer A is runner-verified `PASS` on both stores in partial run
`20260718T122605Z-78454-partial`. Each store exposed one ordinary Cancel control,
confirmed Pending Cancellation with an end date, and removed the original
subscription's future renewal action. The exact primary-product Add to cart
fallback then opened Blocks checkout, where one trusted new-card submission with
Visa 4242 and a store-naming future-payment mandate created a distinct active
$9.99/month subscription with its own pending renewal action.

Reference ended with old subscription `2169` pending cancellation and new active
subscription `2171` on processing order `2170`; target ended with old `1583`, new
`1585`, and order `1584`. Both new orders join exactly through their local
transaction IDs to independent succeeded PaymentIntents, paid/captured charges,
provider PaymentMethods, customer mappings, and saved WooPayments tokens. The
target-only broken product placeholder appears alongside five generic resource
404 console errors; no captured request URL attributes those messages to the
placeholder. The divergence is cosmetic, while relevant local response failures,
page errors, and bounded PHP severity checks are clean.

Accepted result digest:
`sha256:566a874533805abed7ce2753904d989b1f9b33765980e4485fd7a493c8d31cec`.
The matrix row remains `PENDING` until a separate deterministic exerciser is wired
and accepted through the Layer D runner.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
