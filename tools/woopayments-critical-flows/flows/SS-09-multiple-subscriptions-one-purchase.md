# SS-09 — Multiple subscriptions in one purchase · DETERMINISTIC

Guards multi-subscription checkout: a single cart holding two subscription products with DIFFERENT billing schedules ($10.00/month and $50.00/year — differing schedules force WC Subscriptions to split them into separate subscriptions) must produce one paid parent order and TWO active subscriptions, each with its own correct schedule and each independently billable against the shared saved token. A native build that creates only one subscription, collapses the schedules, or leaves one subscription without payment meta (so only one renews) is a regression. No browser layer is required for this flow.

## Fixtures (both stores)

- WC Subscriptions active; connected test account; saved cards enabled ("mixed checkout" / multiple subscriptions permitted).
- Two subscription products: **$10.00 / month** and **$50.00 / year**, no trials, no sign-up fees.
- A registered customer checking out with card `4242 4242 4242 4242` (or an SP-01-seeded saved card).

## Layer D — deterministic state assertion

Exercise a single checkout with both products in the cart, then drive a renewal on each subscription:

- Assert ONE parent order, total **$60.00**, status `processing`/`completed`, with `_intent_id`/`_charge_id` (a single charge for the combined initial total).
- Assert exactly TWO subscriptions were created from that parent order, both `active`.
- Assert per-subscription schedules: the monthly subscription has `next_payment` ≈ +1 month and recurring total $10.00; the yearly one has `next_payment` ≈ +1 year and recurring total $50.00.
- Assert BOTH subscriptions carry valid WooPayments payment meta (`_payment_method_id` family) pointing at the same saved token.
- Drive `WC_Subscriptions_Manager::process_renewal` on EACH subscription; assert each renewal order is paid for its own amount ($10.00 and $50.00) with its own `_intent_id`/`_charge_id`, and each subscription's `next_payment` advances by its own period.
- Compare ref vs target end-state.

### Regression guard

The flow fails when the purchase yields fewer than two subscriptions, when either subscription's schedule does not match its product's own period (monthly vs yearly), when either subscription lacks provider-backed payment meta, or when only one of the two driven renewals can charge. A parent order paid for $60.00 with only one billable subscription is a silent revenue loss — treat it as a functional fail, not a fixture issue.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-09-*.sh.
