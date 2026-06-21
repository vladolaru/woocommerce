---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 10:38
target: A4ao Blocks Express Checkout Element parity
reconciles:
  - staging-log.md
  - implementation-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4am-classic-card-checkout-parity.md
status: draft
---

# A4ao Blocks Express Checkout Element Parity Analysis

> **Prompt:** "ok. continue"

## Current Finding

A4am restored classic card checkout parity, but Blocks Express Checkout Element still has source-backed native gaps against the reference WooPayments Blocks implementation. This is shopper-facing checkout functionality and styling, so it belongs in reopened A4/N12 before the final exit gate.

Source-backed native gaps:

- Native `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/index.js` builds mounted Elements options without the full reference cart-aware pipeline. `getStripeElementsOptions()` currently passes amount, currency, payment method types, and `loader: 'never'`, but does not include manual capture, subscription setup-future-usage, appearance, or locale for the mounted Express Checkout Element.
- Native `getAvailabilityElementsOptions()` probes manual capture and setup-future-usage, but omits the `loader: 'never'`, appearance, and locale options used by the actual rendered surface.
- Native `canMakePayment()` and method registration only use localized `params.enabled_methods`; the reference Blocks container reads `cart.extensions.wcpay.express_checkout_methods` from the Store API cart response and intersects that runtime cart state with localized method availability.
- Native click handling resolves only business name, email/phone, shipping requirement, and allowed countries. The reference passes cart line items and shipping rates to Stripe so wallet sheets reflect the current cart and available shipping options.
- Native does not listen for `shippingaddresschange` or `shippingratechange`; the reference updates `/wc/store/v1/cart/update-customer` and `/wc/store/v1/cart/select-shipping-rate`, checks currency drift, calls `elements.update()`, and resolves Stripe with updated line items and shipping rates.
- Native confirm handling posts directly to `/wc/store/v1/checkout` and does not yet mirror the reference checkout handoff details around order notes, transformed payment data, payment-status handling, redirect fallback, and `api.confirmIntent`.
- Native `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/style.scss` only sets width/min-height. Reference Blocks ECE styles explicitly keep Blocks express checkout containers/items at `overflow: visible` with small horizontal padding so Stripe iframes and focus affordances are not clipped.

Reference anchors:

- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/express-checkout/block-buttons/components/express-checkout-container.js` derives `enabledPaymentMethods` from `cart.extensions.wcpay.express_checkout_methods`, maps that to platform method types, and passes manual capture, subscription setup-future-usage, appearance, locale, and amount options to Stripe Elements.
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/express-checkout/block-buttons/hooks/use-express-checkout.js` and `client/express-checkout/event-handlers.js` provide line items, shipping rates, Store API customer/rate updates, currency-drift handling, checkout handoff, and redirect/confirmation handling.
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/express-checkout/block-buttons/express-checkout-element.scss` provides the overflow-visible Blocks ECE styling missing in native.
- Native `WooPaymentsExpressCheckoutService` already has `get_enabled_methods_for_context( 'cart', $currency )` and `get_allowed_payment_method_types_for_context( 'cart', $currency )`, but no native Store API cart extension currently exposes the cart-aware `extensions.wcpay.express_checkout_methods` contract.

## Slice Boundary

A4ao should restore Blocks Express Checkout Element parity in one coherent slice spanning the Store API cart extension, Blocks ECE runtime options/lifecycle, shopper-visible wallet sheet data, and scoped ECE styling. It should not rework classic card checkout, generic WooCommerce Blocks internals, payment-settings previews, WPCOM/platform server code, or final A4 readiness.

In scope:

- Add a native WooPayments Store API cart extension that exposes `extensions.wcpay.express_checkout_methods` under the existing `wcpay` namespace when native WooPayments is the active runtime.
- Keep the extension provider-owned and fail-closed behind `NativePaymentsRuntimeArbiter`; do not alter WPCOM or standalone WooPayments plugin code.
- Use cart-aware enabled methods and derived platform payment method types for availability probes, mounted Elements options, method registration, and checkout payment data.
- Preserve separate bundle ownership: all WooPayments Blocks ECE logic and styling stays in the existing `wc-payment-method-woopayments-express-checkout` asset, not the card, WooPay, or generic checkout bundles.
- Add the mounted Elements options parity that is independent from WooPay: `loader: 'never'`, manual capture, subscription setup-future-usage, Blocks checkout appearance, and locale.
- Add line items, shipping rates, shipping address/rate lifecycle updates, Elements amount updates, and explicit error handling for currency drift or Store API failures.
- Harden checkout confirmation enough to preserve reference behavior without weakening native backend payment-data contracts.
- Add scoped SCSS so the Blocks ECE iframe/focus styling is not clipped on cart or checkout.

Out of scope:

- No WPCOM repo changes, no WPCOM sandbox access, no trunk push.
- No final A4/N12 readiness flip.
- No classic checkout/card Payment Element changes; A4am owns that surface.
- No WooPay-specific runtime changes unless the same ECE abstraction naturally carries WooPay as another express method.
- No broad refactor of WooCommerce Blocks payment method architecture beyond the seams needed for this provider-owned surface.

## Architecture Notes

The correct abstraction is provider-level platform payment method data: native WooPayments owns which wallet/provider methods are currently available for the cart, and the Blocks ECE frontend consumes that through the same `extensions.wcpay.express_checkout_methods` Store API contract the reference plugin uses. This keeps WooPay, Apple Pay / Google Pay, Link, and Amazon Pay at the same express-checkout abstraction level instead of tying card rendering or WooPay-specific behavior to each other.

The frontend should derive Stripe `paymentMethodTypes` from cart-aware WooPayments express methods and keep localized settings as an allowlist. That preserves dynamic cart/currency gating while avoiding overexposure when the localized provider settings say a method is disabled.

The shipping lifecycle should use Store API cart endpoints and update Stripe Elements from the returned cart totals. It should fail closed and visibly when the cart update fails or the currency changes under the wallet sheet, because incorrect shipping/amount data on checkout is a money-path defect.

## Verification Expectations

- RED PHP tests should fail on the missing native Store API cart extension and missing cart-extension data/schema callbacks.
- RED JS tests should fail on missing cart-extension gating, missing Elements options, missing line items/shipping rates, and missing shipping address/rate handlers.
- GREEN focused gates should include PHP unit tests for the extension, focused Blocks ECE Jest, exact-file JS lint, targeted SCSS Stylelint, PHP syntax/PHPCS/PHPStan for touched PHP, and the relevant Blocks build.
- Browser gates should use Playwriter on target and reference Blocks cart/checkout where the local env can render ECE, capture failed responses/page errors/console issues, and scan target WordPress/WooCommerce logs after proof.
- If local HTTP or wallet prerequisites prevent deterministic live wallet rendering, source/Jest proof must cover the unavailable branch, and the browser proof must honestly record the limitation instead of claiming unsupported runtime behavior.
