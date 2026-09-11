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

## Latest runner evidence

Layer A is runner-verified `PASS` on both stores in partial run
`20260718T151903Z-45965-partial`. Reference subscription `1283` and target
subscription `874` each exposed the exact-row **Suspend** action, changed to
**On hold** with visible list/edit feedback and a new status note, exposed the
exact-row **Reactivate** action, and returned to **Active** with the original
future September 10 schedule and a second status note. Every transition was one
ordinary trusted browser click; hash-bound continuations preserved post-action
screenshot failures without replaying a transition.

The authoritative state series are `active` → `on-hold` → `active`. Suspending
removed the pending renewal action, and reactivating recreated one with the same
hook, group, and GMT schedule. Each store retained its exact recent/related order
inventories, Visa 4242 and Mastercard 4444 token inventories, gateway state,
amount, currency, and payment identifiers; no checkout, renewal drive, new order,
or payment occurred. All twelve accepted screenshots visibly bind the exact
subscription row/action and resulting list/edit state.

The reference marker-bounded debug window retains one WooPayments
translation-loading notice. Target retains twelve exact `settings-ui`
asset-registry exception lines and three bounded aborted `s.w.org` emoji requests
across the two final browser contexts. No accepted context contains an HTTP
response failure, console error, page error, error notice, or functional failure;
neither store is represented as log-clean.

Accepted result digest:
`sha256:81461a442fe04815abcbf54d168ae5d246f59524a5ef91d7007ffdbe7cfe3dca`.
The matrix row remains `PENDING`: Layer A passes independently, while Layer D is
still unwired and no suspended/resumed renewal was driven by this package.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
