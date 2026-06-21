---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 02:59
last_updated: 2026-06-19 03:02
target: exp/core-native-payments — A4l express checkout settings parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4k-settings-row-payload-parity.md
  - ../2026-06-19-woopayments-express-checkout-settings-parity/analysis.md
  - ../2026-06-19-native-woopayments-settings-routes/analysis.md
  - staging-log.md
status: draft
---

# A4l Express Checkout Settings Parity

## Prompt

> Add a task at the bottom of your current task list that, once you are fully done with A5c, I want you to re-open A4 because there is work to be done for feature parity. Read and follow this .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md

## Working Frame

The A5c plan already contains the requested bottom task: `Task 6: Reopen A4 for N12 Admin Feature Parity`. A4 is open again until the native WooPayments admin passes the widened N12 parity gate for reachability, functional and visual fidelity, copy/content fidelity, SCSS fidelity, and the standing stage-boundary gate. A5 readiness remains fail-closed until that happens.

This A4l slice starts from the N12 settings-page backlog item for express checkouts: the reference renders per-method Customize buttons that open dedicated express-checkout settings sub-pages, including payment-request live preview behavior. Native currently only renders inline flat toggles on the provider settings page.

## Source-Mapping Questions

1. What reference components, SCSS, data hooks, bootstrap settings, and URL conventions make up `client/settings/express-checkout-settings/**`?
2. Which native settings-route seam should own the provider sub-routes while staying under WooCommerce Settings > Payments?
3. Which parts should be hoisted as units versus rebuilt/adapted at native edges?
4. What test and browser gates prove merchant-facing parity without overfitting to unstable local screenshots?

## Findings

### Reference Surface

The standalone WooPayments reference mounts `ExpressCheckoutSettings` from `client/settings/index.js` only when `#wcpay-express-checkout-settings-container` exists; the method comes from `data-method-id`. Supported method IDs are `woopay`, `payment_request`, and `amazon_pay`; invalid IDs render `Invalid express checkout method ID specified.` Customize links are generated with `getPaymentMethodSettingsUrl( method )`, which produces `admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&method=<method>`.

`ExpressCheckoutSettings` is a method detail surface, not an inline field group. It wraps method sections in `SettingsLayout`, `FormBusyState`, `SettingsSection`, `LoadableSettingsSection`, `ErrorBoundary`, and the shared `SaveSettingsSection`. The important structural parity point is that save/loading/dirty state applies to the sub-page as a whole, with the reference busy overlay active during saves.

The method detail surface responsibilities are split by method: `PaymentRequestSettings` handles Apple Pay / Google Pay enablement, dynamic checkout payment-method-list mode, product/cart/checkout location arrays, and shared appearance; `AmazonPaySettings` handles Amazon Pay enablement via `enabled_payment_method_ids`, dynamic checkout mode, location arrays, and size-only general settings; `WooPaySettings` handles WooPay enablement, Link conflict notices, locations, logo upload, global theme support, checkout policies/custom message, WooPay preview, and shared appearance when the `woopayExpressCheckout` flag allows it.

Shared appearance is not equivalent to the native inline `SelectControl` grid. Reference `GeneralPaymentRequestButtonSettings` exposes call-to-action labels `default`, `buy`, `donate`, `book`; size radios with visible pixel descriptions `Small (40 px)`, `Medium (48 px)`, `Large (55 px)`; theme radios with descriptions; border radius number plus slider constrained to `0..30`; cross-method notices; Cart & Checkout block override notice; and a preview. The preview is Stripe-backed for Apple/Google and WooPay-backed for WooPay; the native slice can adapt the preview implementation, but should preserve the notices and available controls.

The dynamic checkout payment-method-list setting has functional behavior native currently lacks: when `is_express_checkout_in_payment_methods_enabled` is true, product and cart location checkboxes for Apple/Google and Amazon Pay are disabled and appear unchecked, while checkout is disabled and appears checked. WooPay does not use that override. The reference has explicit tests for this.

Key settings/data fields already exist in native and map cleanly: `is_payment_request_enabled`, `is_express_checkout_in_payment_methods_enabled`, `payment_request_button_type`, `payment_request_button_size`, `payment_request_button_theme`, `payment_request_button_border_radius`, `is_woopay_enabled`, `is_woopay_global_theme_support_enabled`, `woopay_custom_message`, `woopay_store_logo`, `enabled_payment_method_ids`, `express_checkout_product_methods`, `express_checkout_cart_methods`, and `express_checkout_checkout_methods`. Amazon Pay enablement is `enabled_payment_method_ids` membership for `amazon_pay`, not a dedicated boolean setting.

Reference SCSS/assets that matter for this slice include `client/settings/express-checkout/style.scss`, `client/settings/express-checkout-settings/index.scss`, method icons from `payment-methods-map.tsx` and `payment-methods-icons.tsx`, and WooPay preview assets `woopay-preview-product.svg`, `woopay-preview-visa.svg`, `woopay-preview-logo.svg`, and `woopay-preview-payment-cards.svg`. Native should keep this bundle scoped to WooPayments settings/admin chunks and avoid forcing WooPay-specific preview assets onto merchants who do not load the WooPayments settings subpage.

### Native Route And Data Seams

Native WooPayments admin routes are registered in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` through `registerSettingsPaymentsProviderRoute`, then consumed by `SettingsPaymentsMainWrapper` under WooCommerce Settings > Payments. The existing `/woopayments/settings` route is exact; `/woopayments/settings/*` does not currently work and falls through to the generic provider list because React Router v6 exact matching does not match nested paths without explicit route registration or a wildcard parent.

The right route owner remains the WC Settings > Payments provider-route seam. For native, the reference query contract should be adapted to canonical provider sub-routes such as `admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request&from=settings-payments`. `SettingsButton` already treats URLs with a `path` query arg as Reactified and navigates through WC Settings > Payments, so individual provider Manage/Customize links can funnel cleanly through the existing orchestration.

The native settings data store already covers the underlying fields. The main gaps are composition and routing: express sections are currently local unexported components inside `settings-page.tsx`, the inline express settings mix list controls and method detail controls on the main page, there is no native helper for method-specific settings URLs, and there is no method detail component that can be lazy-loaded as a smaller settings subpage.

Bundle splitting needs care. A separate express subpage chunk only helps if extracted components do not import `settings-page.tsx` wholesale. Shared low-level controls and data hooks should be extracted to focused files, and the main page should link to the method detail routes without pulling the whole detail surface into the main settings bundle. Multiple chunks importing `./data/store` should preserve the single `wc/payments/settings` registry.

### RED Targets

First RED coverage should pin route registration and routing behavior: `woopayments/admin/test/routes.test.tsx` should assert the express detail route registrations; `settings-payments/test/register-provider-routes.test.tsx` should assert bootstrap registration; and provider-button tests should verify `path=/woopayments/settings/express-checkout/...` URLs stay inside the WC Settings > Payments React route flow.

Component RED coverage should then pin the merchant-facing parity points: the main express section renders per-method `Customize` links; Apple/Google and Amazon detail pages render the reference enable copy and dynamic checkout override behavior; WooPay detail renders Link conflict copy and core-adapted WooPay policy/logo fields; shared appearance renders CTA, size, theme, border-radius, cross-method notices, and preview/fallback notices; invalid method IDs fail closed.

Browser gates should compare reference `:8082` and target `:8889` for route reachability, Customize navigation, visible copy, dynamic checkout disabled-location behavior, save dirty/busy state, and scoped asset loading. Pixel-perfect performance measurements are not reliable here; use screenshots for large visual regressions and record bundle/chunk names plus payload deltas for N7c.
