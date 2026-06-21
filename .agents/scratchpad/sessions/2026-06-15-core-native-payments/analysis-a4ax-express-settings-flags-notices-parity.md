---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 21:57
last_updated: 2026-06-20 22:47
target: A4ax express checkout settings flags/notices/content parity
reconciles:
  - review-a4au-settings-inventory.md
  - review-a4au-express-checkout-inventory.md
status: final
---

# A4ax Express Settings Flags, Notices, And Content Parity

## Prompt

> Continue working toward the active Core native payments goal after A4aw.

## Source-Backed Gap

The next coherent reopened-A4/N12 slice is express checkout settings parity. A4av closed payment-method guidance parity and A4aw closed VAT deep-link parity; the remaining medium-priority express gap from `review-a4au-express-checkout-inventory.md` is still source-backed in current code.

Native `WooPaymentsSettingsService::get_settings()` exposes the express checkout values consumed by the provider settings UI but does not expose the reference express feature flags. The contract currently returns `is_express_checkout_in_payment_methods_list_supported => true` unconditionally and then WooPay, Amazon, and payment-request settings values, but no `feature_flags` projection for `woopay`, `woopayExpressCheckout`, `isDynamicCheckoutPlaceOrderButtonEnabled`, or `amazonPay` ([WooPaymentsSettingsService.php](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:252)). The reference settings SPA reads those flags from `wcpaySettings.featureFlags`, including the same four express flags in `WC_Payments_Features::to_array()` ([class-wc-payments-features.php](/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-features.php:433)).

Native express overview always includes the WooPay row and includes Amazon Pay based only on `available_payment_method_ids`, not a feature flag ([settings-page.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:886), [settings-page.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1001)). The reference WooPay and Amazon overview rows are feature-gated.

Native detail-page notices count enabled methods but always render the Cart & Checkout blocks override notice even when no other buttons share settings ([notices.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx:76)). The reference first applies WooPay/Amazon feature flags, returns `null` when no other buttons are effectively enabled, and only then renders both warning notices ([express-checkout-settings-notices.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/express-checkout-settings-notices.tsx:67), [express-checkout-settings-notices.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/express-checkout-settings-notices.tsx:95)).

Native payment-request and Amazon detail pages show the payment-methods-list checkbox whenever the native support value is not false ([payment-request-settings.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/payment-request-settings.tsx:90), [amazon-pay-settings.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/amazon-pay-settings.tsx:88)). The reference shows it only when `isDynamicCheckoutPlaceOrderButtonEnabled` is true and changes the Apple/Google help copy when Amazon Pay is feature-enabled ([payment-request-settings.js](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/payment-request-settings.js:56), [payment-request-settings.js](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/payment-request-settings.js:70)).

Native WooPay detail help text keeps several reference links as plain copy: the disabled-state WooPay help links Terms, Privacy, and usage tracking, but the word WooPay itself is not linked to merchant docs ([woopay-settings.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:266)); global-theme help lacks the reference `Learn more` link ([woopay-settings.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:322)); checkout policies help is plain text and lacks the reference store privacy/terms and WooPay custom-policy documentation links ([woopay-settings.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:336)). The reference has all three link groups ([woopay-settings.js](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/woopay-settings.js:100), [woopay-settings.js](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/woopay-settings.js:258), [woopay-settings.js](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/woopay-settings.js:286)).

## Architecture Decision

Keep the work inside the existing native provider settings seam instead of adding another shared preload or top-level settings channel. `WooPaymentsSettingsService` already owns the REST settings contract for this Core-owned provider UI, and the express settings components already read the full settings record through `useGetSettings()`. Add a small `feature_flags` object there, with a typed frontend helper that defaults missing flags to the reference-safe values for backward compatibility during local tests.

Reuse existing native provider helpers where they already encode runtime eligibility. The shopper-runtime `WooPaymentsExpressPaymentMethodTypes` has Amazon runtime checks, but the settings UI needs reference feature flag parity, not a shopper availability rewrite. For this slice, expose the merchant-setting feature switches and keep deeper account/currency availability logic in the existing availability/status paths.

Scope this as one implementation chunk because the settings contract, overview rows, detail notices, dynamic-list checkbox, and WooPay help links all depend on the same express feature flag source. Splitting them would leave the UI in a half-gated state.

## Subagent Reconciliation

Bernoulli the 6th independently verified the same source-backed gaps with no file changes and no WPCOM access. The report confirmed that native lacks the express `feature_flags` settings projection, overview/detail gating still ignores WooPay/Amazon feature flags, dynamic checkout list gating is hardcoded through `is_express_checkout_in_payment_methods_list_supported`, and notices render the Cart & Checkout override message even when no other express method shares settings.

The report corrected one stale part of the initial candidate: native already has the overview WooPay docs, Terms, Privacy, and usage-tracking links in `settings-page.tsx`; the remaining WooPay link gaps are on the WooPay detail page only. It also called out an implementation risk: do not conflate saved settings like `is_woopay_enabled` with eligibility/feature flags. Reference `featureFlags.woopay` is eligibility, `featureFlags.woopayExpressCheckout` combines eligibility with the express flag, and the settings controller separately combines eligibility with the saved `platform_checkout` value for `is_woopay_enabled`.

## Verification Strategy

Start with RED coverage in `WooPaymentsSettingsServiceTest`, `settings-page.test.tsx`, and `express-checkout-settings.test.tsx`. Then implement minimal contract/frontend helpers. Browser proof should use the existing settings routes on the target store for overview, payment request, WooPay, and Amazon details; because flags are mostly synthetic/local settings-state behavior, deterministic Jest/PHP tests carry the flag matrix and Playwriter should prove no route, rendering, styling, or log regressions in the real target state.

## Closeout

A4ax closed with source/tests commit `1ece74defe` and changelog commit `2e502b833c`. The implementation corrected the contract nuance found during review: `woopay` is native eligibility, while `woopayExpressCheckout` is the raw option-backed flag, and effective WooPay express availability is derived in frontend helpers where the UI needs both. Amazon Pay overview and direct-route availability also now require both the feature flag and `available_payment_method_ids` membership. Link conflict handling uses effective WooPay express availability so hidden saved WooPay state does not block Link when WooPay is feature-disabled.

Final evidence: focused `WooPaymentsSettingsServiceTest` passed with 30 tests / 246 assertions, focused Jest over `settings-page.test.tsx` and `express-checkout-settings.test.tsx` passed with 108 tests, exact-file ESLint passed, PHP syntax passed, changed-file PHPCS passed, production PHPStan for `WooPaymentsSettingsService.php` passed, admin TypeScript lint passed, admin bundle build passed, Playwriter target smoke passed under `data/a4ax-express-settings-parity/`, target `debug.log` was 0 bytes, recent target Docker-log scans were clean, changelog validation passed, `git diff --check -- . ':!.agents'` passed, and branch `lint:changes:branch` passed. Review fixes closed the over-mocked Link regression test and the Amazon direct-route account-availability gap before commit.
