# MS-07 — Admin change payment method on a subscription · HYBRID (A + D)

Regression test for B3. Both stores must preserve the `woocommerce_subscription_payment_meta` family and the `subscription_payment_method_change_admin` support flag.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- One active subscription paid via WooPayments (seed via SS-01).
- A second saved card on the customer (so there is a token to select/set).

## Layer A — agent-driven browser (primary; the failure is in admin UI)

BOTH stores:

1. WP Admin → WooCommerce → Subscriptions → open the subscription → Edit.
2. In Billing, confirm WooPayments is selectable as the payment method.
3. **Confirm the editable saved-token selector renders** via `woocommerce_subscription_payment_meta`.
4. Set/correct the WooPayments token, Save.
5. **Functional:** the subscription persists a valid token; trigger "Process renewal" (or wait for the scheduled renewal) and confirm it charges the chosen token and the renewal order completes.
6. **UX:** the admin can actually configure the instrument — not just select WooPayments with no fields.

## Layer D — deterministic state assertion (overlap)

After the admin save + a driven renewal:

- Assert subscription meta `_payment_method_id` / `_stripe_customer_id` set to the chosen token (not empty).
- Assert reselecting a previously used token appends it as the active order-token relationship; WooPayments treats the final relationship as active.
- Assert the renewal order is `processing`/`completed` with `_intent_id`/`_charge_id`.
- Compare ref vs target end-state.

### Regression guard

The flow fails when WooPayments advertises admin payment-method changes but does not render a saved-token selector, when the selected token does not become the active final relationship, or when the next renewal cannot charge that provider-backed token. Passing requires browser and renewal-state evidence on both stores.
