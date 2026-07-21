# MS-01 — Create subscription product · HYBRID (A + D)

Guards the merchant's ability to author a subscription product that WooPayments can actually bill. The regression to catch: the "Simple subscription" product type or its billing fields (price, interval, period) fail to render/save under native, or the published product is not purchasable because the native gateway drops the `subscriptions` support flag — the shopper would see "no payment method supports subscriptions" at checkout.

## Fixtures (both stores)

- WC Subscriptions active; connected test account.
- No pre-existing product needed; the flow authors one.
- Before authoring, read and record whether `woocommerce_subscriptions_add_to_cart_button_text` exists and its exact value on each store; do not change it.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → Products → Add New. Title "MS-01 Monthly Box".
2. Product data dropdown: **"Simple subscription" is offered as a product type**.
3. Set subscription price `$10`, billed every `1` `month`. **The subscription pricing fields (price / interval / period) render and accept values.**
4. Publish. **Success notice; product status Published.**
5. Read-only, capture the exact effective CTA returned by the WooCommerce Subscriptions product owner for the published product. Keep the raw option presence/value as provenance, not as the final display oracle: the current missing-option source default is **Add to cart** before translation and supported CTA filters.
6. View the product on the storefront: **the recurring price string renders (e.g. "$10.00 / month") and an enabled, non-empty add-to-cart action exactly matches the independently captured effective CTA**.
7. Add to cart → Checkout: **WooPayments is offered as a payment method** (no "no gateway supports subscriptions" fallback).

End state: a published, purchasable subscription product on both stores, with WooPayments selectable for it at checkout.

## Layer D — deterministic state assertion

- Assert the product exists with type `subscription` and meta `_subscription_price=10`, `_subscription_period=month`, `_subscription_period_interval=1`.
- Assert `wc_get_product( $id )->is_purchasable()` is true.
- Record the CTA option's presence/value and the exact effective CTA returned by the WooCommerce Subscriptions product object. Treat the effective product value as the sole display-literal oracle; do not infer the final literal directly from the untranslated default or raw option because translation and supported CTA filters may transform it.
- Assert Layer A's visible, enabled action exactly equals that independently captured effective CTA for the same store.
- Assert the WooPayments gateway reports `supports( 'subscriptions' )` so carts containing the product keep the gateway available.
- Compare ref vs target end-state (same product meta, same gateway availability).

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MS-01-*.sh.

## Latest runner evidence

Layer A is runner-verified `FAIL — UX` on both stores in partial run
`20260718T141201Z-30996-partial`. Each store offered Simple subscription,
rendered and accepted the exact `$10`, every `1` `month` fields, published the
product through one trusted action with visible Published feedback, rendered the
recurring `$10.00 / month` price, and exposed WooPayments at checkout. No checkout
payment was submitted and the recent-order inventories are unchanged.

Reference product `2181` and target product `1593` are published, purchasable
`subscription` products with exact matching metadata. The plugin and native
gateways are enabled and independently report `subscriptions` support. However,
both storefronts render **Add to cart**, not the specified **Sign up now**
affordance. This is a shared reference/native UX contract failure, not a native
parity regression. The target's broken product placeholders are a separate visual
divergence.

Two reference keyboard-selection attempts stopped before publication. WordPress
retained one simple-product draft (`2180`); the runner-bound contract product is
the distinct published subscription `2181`. The reference diagnostic window also
retains one translation-loading notice and gateway debug lines, while target
retains five exact `settings-ui` asset-registry exception lines; neither store is
represented as log-clean. Each post-publish screenshot-timeout attempt also
retains the exact page error `AbortError: Transition was skipped`; its hash-bound
continuation reopens the already-published product without replaying Publish, and
the final observation contexts contain no page errors.

Accepted result digest:
`sha256:1d53bfa6c4191a956904c8a1f161506685e215e1fec26809c86d3c37fa4308fc`.
The matrix row remains `PENDING`: Layer A failed independently and Layer D remains
unwired.

## 2026-07-21 contract correction

- The accepted run and its historical dual-store `FAIL — UX` verdict remain unchanged; they evaluated the former exact **Sign up now** assertion.
- WooCommerce Subscriptions owns this CTA and deliberately changed its untranslated missing-option source default to **Add to cart** in 7.8.0 while preserving stored merchant overrides, translation, and supported CTA filters. Neither WooPayments owner should replace the effective product value.
- The accepted evidence proves that both products resolved **Add to cart**. It does not prove whether the underlying option was absent or persisted with the same value.
- The row remains `PENDING` because Layer D is unwired. A future run must independently capture option provenance and the owner-resolved CTA; the corrected assertion does not retroactively turn the accepted Layer A result into a pass or establish comparative shopper or assistive-technology comprehension.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
