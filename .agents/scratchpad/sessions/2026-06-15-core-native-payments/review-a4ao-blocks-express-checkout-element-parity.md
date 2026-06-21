---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 10:59
target: A4ao Blocks Express Checkout Element parity
reconciles:
  - analysis-a4ao-blocks-express-checkout-element-parity.md
  - plans/2026-06-20-core-native-payments-a4ao-blocks-express-checkout-element-parity.md
status: final
last_updated: 2026-06-20 12:00
---

# A4ao Review Reconciliation

> **Prompt:** "ok. continue"

## Subagent Findings

Four A4ao review agents completed static review of the uncommitted Blocks Express Checkout Element parity diff.

- Architecture/simplification approved with no critical, high, or medium findings. It recorded low residual risks around wrapper-level SCSS breadth and cart-scoped method types, both accepted as non-blocking because the clipping originates in parent Blocks wrappers and the server still validates payment method data.
- JS test review found three medium test-quality gaps: shipping-address assertions could pass stale cart data, shipping-rate assertions did not prove line items refreshed, and the cart-extension gating assertion was brittle because it required synchronous `false` rather than accepting a promise-returning `canMakePayment` implementation.
- Reliability/API review found one high issue: `transformPrice()` used `||` fallback semantics for `currency_minor_unit`, checkout decimals, and `stripe_minor_unit`, so explicit zero-decimal values would be treated as missing and could scale JPY/KRW wallet totals incorrectly. It also found one medium cart-state reliability gap: wallet shipping Store API mutations could survive cancel or checkout failure without refreshing Blocks cart state.
- Frontend/a11y review found three medium issues: unsuccessful `confirm` flows did not call Stripe's `event.paymentFailed()` wallet-sheet API, no-shipping-rates rejections lacked a Blocks notice/live announcement, and single-wallet `div` wrappers could still clip iframe/focus affordances because the CSS only handled `li` items.

## Source Verification

The zero-decimal issue is source-backed: the reference WooPayments transformer in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/express-checkout/transformers/wc-to-stripe.js` uses `??` for `stripe_minor_unit`, while native A4ao used `||` and therefore collapsed explicit `0` to `2`. Store API cart totals expose `currency_minor_unit`, so explicit zero is a valid runtime value.

The `paymentFailed()` issue is source-backed by Stripe's official Express Checkout confirm-event reference: the confirm event exposes `paymentFailed(payload)` with `reason` and `message`. Native should call it opportunistically on payment failures while still using the Blocks error surface for accessibility and fallback.

The no-shipping-rates issue is source-backed by the reference WooPayments event-handler comment: `event.reject()` alone only surfaces a generic wallet message, so the Blocks error surface should explain the failure.

The single-wallet wrapper issue is source-backed by Core Blocks `express-payment-methods.tsx`, which uses `div` wrappers/items when exactly one express payment method is available.

## Remediation Plan

- Fix amount conversion to preserve explicit zero minor-unit values and add a zero-decimal regression.
- Refresh the Blocks cart store after wallet cancel/failure paths that may follow Store API shipping mutations.
- Call `event.paymentFailed()` when confirm failures occur, without depending on it being present.
- Surface a Blocks error before rejecting shipping-address updates with no rates.
- Extend CSS to single-wallet `div` wrappers and make the horizontal padding width-safe.
- Strengthen the wallet lifecycle tests so stale cart data would fail, and make the gating test await the `canMakePayment` result rather than asserting synchronous shape.

## Late Review Findings

- Final local code review found one high runtime-contract bug: `ExpressCheckoutContent` depended on a non-public `cart` prop, and the tests hid the bug by injecting that prop directly. Source verification against the Blocks payment-method interface showed express content receives `cartData`, `billing`, and `shippingData`; the native implementation now normalizes those public props into the Store API-like shape used by ECE and keeps full Store API responses from wallet shipping mutations as the later source of truth. The public-prop tests failed first on cart-aware Elements options and wallet click line items/rates, then passed after the production fix.
- Browser parity proof found one checkout WooPay regression: the native checkout route hid WooPay when the cart contained a subscription, while the reference checkout still rendered WooPay. Source verification showed the native WooPay express registration advertised only `[ 'products' ]`, while the reference forwards the gateway feature list. Native WooPay now uses `settings.supports || settings.features || [ 'products' ]` and has a regression test asserting the `subscriptions` support list is forwarded.
- Chandrasekhar the 6th completed the final read-only code review with critical 0, high 2, medium 0. Both high findings were source-backed and fixed before commit. First, the Store API cart extension used `get_enabled_methods_for_context( 'cart', ... )`, so checkout wallets could be hidden when cart-location methods were disabled but checkout-location methods were enabled; the extension now returns a currency-fresh, location-blind union over product/cart/checkout and the client still intersects that with localized page methods. Second, filtered subscription shipping rates could post package `0`; native ECE now applies the reference `wcpay.express-checkout.shipping-package-id` filter before calling `/wc/store/v1/cart/select-shipping-rate`.

## Current Disposition

- Fixed: zero-decimal and Stripe special-case minor-unit conversion, wallet Store API mutation refresh, unsuccessful confirm `paymentFailed()` handling, no-rates Blocks error surfacing, single-wallet wrapper styling, non-public Blocks prop dependency, WooPay express feature support forwarding, location-blind Store API ECE method data, and filtered subscription shipping package selection.
- Verification is green through the exact RED/GREEN tests added for the final review findings: `WooPaymentsExpressCheckoutStoreApiExtensionTest` now passes with 4 tests and 11 assertions, and the express checkout Jest file now passes with 23 tests. Earlier focused PHP, focused JS, exact frontend/backend static gates, Blocks bundle build, and Playwriter target/reference cart and checkout proof also passed. `lint:lang:types` for Blocks exits 0 but still prints broad unrelated existing TypeScript errors outside the WooPayments express/WooPay files, so it is recorded as a caveat rather than a clean type assertion.
