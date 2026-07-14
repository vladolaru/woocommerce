# MS-03 — Suspend and resume a subscription · HYBRID (A + D)

Guards the admin suspend/resume lifecycle. The regression to catch: under native, an `on-hold` subscription still gets charged when a renewal fires (money moved for a suspended subscription — the worst outcome), or resume fails to restore a billable `active` state, or the Suspend/Reactivate row actions and status feedback disappear from WooCommerce → Subscriptions.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- One active subscription paid via WooPayments with a saved token (seed via SS-01), next payment date in the future.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Subscriptions; hover the seeded subscription's row. **A "Suspend" row action is discoverable.**
2. Click Suspend. **The status column flips to On hold with visible feedback (no error notice).**
3. Open the subscription → Edit. **Status select shows On hold; an order note records the status change.**
4. Back on the list, hover the row again. **A "Reactivate" (resume) action is discoverable.**
5. Click Reactivate. **Status returns to Active and Billing Schedule still shows a future next-payment date.**

End state: subscription Active again with an intact schedule; both status transitions recorded in order notes.

## Layer D — deterministic state assertion

- Suspend deterministically (`$subscription->update_status( 'on-hold' )`), then drive `WC_Subscriptions_Manager::process_renewal( $id )`: assert NO paid renewal order is created — no new order with `_intent_id`/`_charge_id`, and any renewal order left behind is unpaid. On-hold must block the charge.
- Resume (`update_status( 'active' )`), drive the renewal again: assert a renewal order IS created with status `processing`/`completed`, `_intent_id` and `_charge_id` present, charged against the subscription's saved token.
- Assert subscription status is `active` and `_schedule_next_payment` advanced after the successful renewal.
- Compare ref vs target end-state (same block-while-suspended behavior, same post-resume renewal outcome).

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-03-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
