# MS-05 — Renew automatically (scheduled) · DETERMINISTIC

Guards the unattended renewal path — the core promise of a subscription. When the subscription's next-payment timestamp comes due, Action Scheduler fires `woocommerce_scheduled_subscription_payment`, and WooPayments must charge the saved token off-session (no shopper present), create a paid renewal order, send the renewal email, keep the subscription `active`, and advance the next-payment date one full billing period. The regression to catch: native's scheduled-payment handler silently fails to charge, charges but leaves the schedule stuck (double-charge risk on the next run), or completes without merchant/customer email. Everything here is timer- and state-driven, so No browser layer is required for this flow.

## Fixtures (both stores)

- WC Subscriptions active; connected test account; mail catcher capturing outbound email.
- One active subscription paid via WooPayments with a saved token (seed via SS-01), `$10/month`.

## Layer D — deterministic state assertion

On BOTH stores:

1. Backdate the schedule: set `_schedule_next_payment` (and the pending Action Scheduler action's timestamp) to 5 minutes in the past.
2. Run the due queue: `wp action-scheduler run --hooks=woocommerce_scheduled_subscription_payment` (or process due actions) so the renewal fires exactly as cron would.

Assertions:

- The scheduled action for this subscription reaches status `complete` in Action Scheduler (not `failed`, not still `pending`).
- A renewal order exists for the subscription, status `processing`/`completed`, with `_intent_id` and `_charge_id`; charged amount equals the subscription total (`$10.00`); the charge used the saved token off-session (no new payment method created on the customer).
- The renewal/processing-order email was captured by the mail catcher for the customer.
- The subscription remains `active` and `_schedule_next_payment` advanced one full billing period beyond the fired date — no stuck schedule, no immediate re-fire pending.
- Debug log clean of renewal-path errors.
- Compare ref vs target end-state: same renewal order status/amount/meta shape, same advanced schedule, email present on both.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-05-*.sh.
