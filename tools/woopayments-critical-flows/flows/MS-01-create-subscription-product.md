# MS-01 — Create subscription product · HYBRID (A + D)

Guards the merchant's ability to author a subscription product that WooPayments can actually bill. The regression to catch: the "Simple subscription" product type or its billing fields (price, interval, period) fail to render/save under native, or the published product is not purchasable because the native gateway drops the `subscriptions` support flag — the shopper would see "no payment method supports subscriptions" at checkout.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- No pre-existing product needed; the flow authors one.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → Products → Add New. Title "MS-01 Monthly Box".
2. Product data dropdown: **"Simple subscription" is offered as a product type**.
3. Set subscription price `$10`, billed every `1` `month`. **The subscription pricing fields (price / interval / period) render and accept values.**
4. Publish. **Success notice; product status Published.**
5. View the product on the storefront: **recurring price string renders (e.g. "$10.00 / month") and the add-to-cart button reads "Sign up now"**.
6. Add to cart → Checkout: **WooPayments is offered as a payment method** (no "no gateway supports subscriptions" fallback).

End state: a published, purchasable subscription product on both stores, with WooPayments selectable for it at checkout.

## Layer D — deterministic state assertion

- Assert the product exists with type `subscription` and meta `_subscription_price=10`, `_subscription_period=month`, `_subscription_period_interval=1`.
- Assert `wc_get_product( $id )->is_purchasable()` is true.
- Assert the WooPayments gateway reports `supports( 'subscriptions' )` so carts containing the product keep the gateway available.
- Compare ref vs target end-state (same product meta, same gateway availability).

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-01-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
