# SS-04 — Set / change default payment method (renewals follow it) · DETERMINISTIC

Guards the interaction between the My Account set-default control and subscription billing: when the shopper makes a different saved card the default and opts to update their subscription(s), the subscription's payment meta must repoint at the new default and the next renewal must charge it — not the card the subscription was purchased with. A native build where set-default flips the token flag but renewals silently keep charging the old instrument is a money-moving regression. No browser layer is required for this flow.

## Fixtures (both stores)

- WC Subscriptions active; connected test account; saved cards enabled.
- One active subscription paid via WooPayments on card A `4242…4242` (seed via SS-01).
- A second saved card B `5555 5555 5555 4444` on the same customer (seed via SP-01).

## Layer D — deterministic state assertion

Exercise the set-default state change the My Account control performs (token default flag + subscription update opt-in), then drive a renewal:

- Set token B as the customer default (`WC_Payment_Tokens::set_users_default`) and apply the update-subscriptions opt-in so the subscription's payment meta repoints at token B.
- Assert token B has `is_default` true and token A no longer does (exactly one default for the WooPayments gateway).
- Assert the subscription's payment meta (`_payment_method_id` family) points at token B's provider `pm_…` ID.
- Drive a renewal (`WC_Subscriptions_Manager::process_renewal` or the scheduled action); assert the renewal order is `processing`/`completed` with `_intent_id`/`_charge_id` present.
- Assert the renewal charged token B's PaymentMethod (charge PM/fingerprint matches card `4444`, not `4242`).
- Assert the subscription stays `active` and `next_payment` advances by one billing period.
- Compare ref vs target end-state.

### Regression guard

The flow fails when the default flag flips but the subscription's payment meta still points at token A, when the driven renewal charges card `4242` despite meta pointing at B, or when set-default leaves zero or two tokens flagged default. End-state divergence between the stores on any of the assertions above is a functional fail on the target.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SS-04-*.sh.
