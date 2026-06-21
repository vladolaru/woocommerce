---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 01:48
target: exp/core-native-payments — A4k express checkout settings parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4j-settings-payment-methods-parity.md
status: superseded
superseded_by: analysis-a4k-settings-row-payload-parity.md
last_updated: 2026-06-19 01:52
---

# A4k Express Checkout Settings Parity

## Superseded

This draft captured the initial local branch of the A4k decision. It is superseded by `analysis-a4k-settings-row-payload-parity.md` after both explorers recommended keeping express customize subpages and fraud advanced as separate deeper workflows, and closing the shared settings payload/payment-method row extras first.

## Initial Source Comparison

N12 leaves several settings parity blockers after A4j. The next coherent implementation slice is express checkout settings parity because the reference treats WooPay, Apple Pay / Google Pay, and Amazon Pay as one settings sub-system: a main Express checkouts list with per-method `Customize` buttons, plus method-specific settings pages with enable, location, appearance, preview, and save-state behavior. Native currently flattens those controls into `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`, so merchants lose the reference grouping, subpage reachability, and live preview affordances even though most underlying settings keys already exist in the native `wc/payments/settings` store.

Reference source anchors are `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout/index.js`, `apple-google-pay-item.tsx`, `woopay-item.tsx`, `amazon-pay-item.tsx`, and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/**`. The main reference list renders a card of `PaymentMethodItem` rows for WooPay, Apple Pay / Google Pay, Link, and Amazon Pay, with icons, merchant-facing descriptions and terms copy, duplicate notices where applicable, WooPay incompatibility notices, Amazon Pay availability notices, and `Customize` buttons linking to `section=woocommerce_payments&method=woopay`, `method=payment_request`, and `method=amazon_pay`.

The method-specific reference pages in `express-checkout-settings/index.js` group each method into sections. WooPay has enable, checkout appearance, and settings sections; Apple Pay / Google Pay has enable and settings sections; Amazon Pay has enable and settings sections. The controls are in `payment-request-settings.js`, `woopay-settings.js`, `amazon-pay-settings.js`, `general-payment-request-button-settings.js`, `payment-request-button-preview.js`, and `woopay-preview.js`, with styling in `index.scss`. Reference save behavior wraps the whole method page in `FormBusyState` and uses the shared `SaveSettingsSection`.

Native has strong seams for this slice already. `plugins/woocommerce/client/admin/client/woopayments/settings/data/hooks.ts` exposes `usePaymentRequestEnabledSettings`, `useExpressCheckoutInPaymentMethodsEnabledSettings`, `usePaymentRequestButtonType`, `usePaymentRequestButtonSize`, `usePaymentRequestButtonTheme`, `usePaymentRequestButtonBorderRadius`, `useWooPayEnabledSettings`, `useWooPayGlobalThemeSupportEnabledSettings`, `useWooPayCustomMessage`, `useWooPayStoreLogo`, `usePaymentRequestLocations`, `useWooPayLocations`, `useAmazonPayLocations`, `useAmazonPayEnabledSettings`, `useLinkEnabledSettings`, and `useSettings`. `WooPaymentsSettingsService` already maps those keys to the preserved gateway settings and persists the location lists. This argues for a frontend route/component parity slice first, with backend widening only if source verification shows missing feature flags or preview inputs.

The route seam should remain under Core-owned Settings > Payments provider sub-routes, not the plugin-era `method=` full-page mount. A native path such as `/woopayments/settings/express-checkout/:methodId` or `/woopayments/settings/express-checkout` with a query parameter can be registered in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` through the existing `registerSettingsPaymentsProviderRoute()` mechanism. The main settings page should link to that canonical route while preserving reference copy and visible `Customize` CTA behavior.

This slice should not pull in fraud protection advanced settings, deposits bank account display, sandbox switch-to-live notice, fee/promotion/duplicate payload widening, or dashboard parity. Those are source-adjacent but not coupled to express checkout routes. Duplicate notices on the express rows are related to the already-missing duplicate payload and can either be implemented if the native payload is widened in this slice, or recorded explicitly as a residual duplicate-notice blocker shared with A4j.

Boundaries remain unchanged: no WPCOM sandbox access or WPCOM code changes, no standalone WooPayments client edits, no Stripe Billing UI, no separate `@wordpress/data` registry, and no globally loaded WooPayments-specific assets. Express checkout settings assets and styles should stay bundled through WooCommerce's existing admin build workflow and scoped to the native WooPayments settings/admin chunks.

## Native Seam Explorer Report

Beauvoir the 3rd mapped the remaining native settings seams read-only. The report confirmed that express checkout customize/live-preview data is mostly ready in native: enablement, locations, button type, size, theme, and border radius already exist in selectors/actions and backend mappings. It also found one concrete current bug in the inline native express controls: `Book` is offered as a button type option, but the normalizer allow-list omits `book`, so the current UI can render an option that normalizes away.

The native report recommends a different next slice than the initial local express direction: “native settings parity payload + PM/payout/settings-shell polish.” The reason is that A4j intentionally left the row-level extras unclaimed because the payload was not ready. The main widening point is `WooPaymentsSettingsService::get_settings()`, surfaced through `/wc/v3/payments/settings`, with read payloads for fees, duplicate payment methods, PM promotions or recommendation metadata, masked payout bank account, and switch-to-live eligibility/setup URL. Dismissals for duplicate notices can already use the allowlisted `/wc/v3/payments/settings/:option_name` path for `wcpay_duplicate_payment_method_notices_dismissed`.

The native report also notes the following current-state facts: duplicate payment method selectors already exist but the backend always returns an empty array; fees from cached account data are read only to infer available payment methods, not exposed to the settings payload; payout schedule/status/currency data exists but no masked external bank account payload exists; fraud payload exists for `current_protection_level` and `advanced_fraud_protection_settings`, while the UI is only a single select and rule count; the account endpoint has `test_drive`, `sandbox`, `live`, and setup URL data, but the settings page does not consume it for switch-to-live; save state exists but lacks reference `FormBusyState`-style polish and can double-surface notices plus inline text.

This finding changes the slice selection decision. Express checkout remains a strong coherent slice, but closing the main settings payload and shell extras first may better finish the A4j row foundation and remove several smaller N12 blockers in one pass. Fraud advanced and express customize subpages are both deeper UI workflows and should not be mixed into a payload-polish slice.
