---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 21:57
last_updated: 2026-06-20 22:47
target: A4ax express checkout settings flags/notices/content parity
reconciles:
  - analysis-a4ax-express-settings-flags-notices-parity.md
  - review-a4au-express-checkout-inventory.md
status: final
---

# A4ax Express Settings Flags, Notices, And Content Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments express checkout settings parity for feature flags, detail-page shared notices, payment-methods-list controls, and WooPay help links.

**Architecture:** Keep the feature source in the existing native WooPayments settings REST contract owned by `WooPaymentsSettingsService`, then consume it through small frontend helpers from the settings record. Preserve the existing native Settings > Payments route ownership and sub-SPA components; do not add another global settings preload or plugin-era `window.wcpaySettings` dependency.

**Tech Stack:** PHP settings service + PHPUnit, React/TypeScript, WordPress components/i18n, WooCommerce admin Jest/RTL, Playwriter for browser proof.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` to add a `feature_flags` settings field with express checkout flags.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php` to assert the new contract keys and flag behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/settings-utils.ts` to add typed helpers for express feature flags and dynamic payment-methods-list support.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` to gate WooPay and Amazon overview rows with the feature flags.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx` to apply feature flags to shared-notice counts and return null when no other enabled express methods share settings.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/payment-request-settings.tsx` and `amazon-pay-settings.tsx` to gate payment-methods-list controls through the dynamic checkout feature flag and preserve the reference Amazon-aware help copy.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx` to restore the missing WooPay documentation, global-theme Learn more, and checkout-policy links.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx` and `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx` for RED/GREEN parity coverage.
- Add `plugins/woocommerce/changelog/fix-native-payments-a4ax-express-settings-parity`.
- Update `implementation-log.md`, `staging-log.md`, and this plan as each phase completes.

## Task 1: RED Tests

- [x] Add `WooPaymentsSettingsServiceTest` coverage that `get_settings()` includes `feature_flags` with boolean `woopay`, `woopayExpressCheckout`, `isDynamicCheckoutPlaceOrderButtonEnabled`, and `amazonPay` keys.
- [x] Add settings-page Jest coverage that a settings response with `feature_flags.woopay=false` hides the WooPay overview row and does not let WooPay participate in Link conflict UI.
- [x] Add settings-page Jest coverage that `feature_flags.amazonPay=false` hides the Amazon Pay overview row even when `available_payment_method_ids` contains `amazon_pay`.
- [x] Add express-settings Jest coverage that `ExpressCheckoutSettingsNotices` renders no notices when no other effectively enabled buttons share settings.
- [x] Add express-settings Jest coverage that disabled WooPay/Amazon feature flags exclude those methods from shared-notice copy even when their settings booleans are true.
- [x] Add express-settings Jest coverage that `feature_flags.isDynamicCheckoutPlaceOrderButtonEnabled=false` hides the payment-methods-list checkbox for Apple Pay / Google Pay and Amazon Pay.
- [x] Add express-settings Jest coverage that Apple Pay / Google Pay payment-methods-list help mentions Amazon Pay only when `feature_flags.amazonPay=true`.
- [x] Add express-settings Jest coverage that WooPay detail help includes links to WooPay merchant docs, global theme checkout appearance docs, store privacy/terms pages when present, and WooPay checkout appearance docs.
- [x] Run RED commands and record expected failures: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsSettingsServiceTest`, and from `plugins/woocommerce/client/admin`, `pnpm exec jest --config client/jest.config.js --runTestsByPath client/woopayments/settings/test/settings-page.test.tsx client/woopayments/settings/test/express-checkout-settings.test.tsx --runInBand`.

## Task 2: Settings Contract Implementation

- [x] Add constants for `_wcpay_feature_woopay_express_checkout`, `_wcpay_feature_dynamic_checkout_place_order_button`, and `_wcpay_feature_amazon_pay` in `WooPaymentsSettingsService.php`, using reference-compatible option names and native defaults that keep currently available native UI visible unless a flag is explicitly disabled.
- [x] Add a private `get_feature_flags(): array` helper returning the four express flags as booleans: `woopay` as native WooPay eligibility, `woopayExpressCheckout` as the raw option-backed WooPay Express Checkout flag, `isDynamicCheckoutPlaceOrderButtonEnabled` as the dynamic placement flag, and `amazonPay` as the Amazon Pay feature flag.
- [x] Add `'feature_flags' => $this->get_feature_flags()` to the settings response.
- [x] Update required-key assertions and the representative `get_settings()` assertions in `WooPaymentsSettingsServiceTest`.
- [x] Run focused PHPUnit until GREEN.

## Task 3: Frontend Feature Flag Helpers And Overview Rows

- [x] Add `getExpressCheckoutFeatureFlags( settings: SettingsRecord )`, `isExpressFeatureEnabled( settings, flagName, fallback )`, and update `isExpressCheckoutInPaymentMethodsListSupported()` in `settings-utils.ts` so the dynamic-list checkbox requires both the existing support field and `feature_flags.isDynamicCheckoutPlaceOrderButtonEnabled`.
- [x] In `settings-page.tsx`, read the settings record once in `ExpressCheckoutSettingsSection`, gate the WooPay row on `feature_flags.woopay`, and gate the Amazon row on both `feature_flags.amazonPay` and `isAmazonPayAvailable`.
- [x] Keep Link/WooPay conflict behavior unchanged when WooPay is feature-enabled, and ensure Link remains available when WooPay is feature-disabled.
- [x] Run focused settings-page Jest until GREEN.

## Task 4: Detail Notices And Dynamic-List Copy

- [x] In `notices.tsx`, read feature flags from `useGetSettings()`, treat WooPay and Amazon as effectively enabled only when their feature flags are enabled, and return `null` when the effective other-button list is empty.
- [x] Keep the Cart & Checkout blocks override notice paired with the shared-settings notice, matching the reference: it renders only when at least one other express method shares the appearance settings.
- [x] In `payment-request-settings.tsx`, use the feature flags to choose the Apple/Google dynamic-list help copy with or without Amazon Pay.
- [x] In `amazon-pay-settings.tsx`, rely on the updated support helper so the dynamic-list checkbox hides when dynamic checkout placement is disabled.
- [x] Run focused express-settings Jest until GREEN.

## Task 5: WooPay Detail Help Links

- [x] In `woopay-settings.tsx`, use `createInterpolateElement` or the existing link pattern so the disabled WooPay help links `WooPay` to `https://woocommerce.com/document/woopay-merchant-documentation/` while preserving Terms, Privacy, and usage-tracking links.
- [x] Add the reference global theme `Learn more` link to `https://woocommerce.com/document/woopay-merchant-documentation/#checkout-appearance`.
- [x] Add checkout policy help links for store privacy and terms pages when present in `window.wcSettings.storePages`, plus the WooPay checkout appearance docs link.
- [x] Keep link text and labels sentence-case or reference-faithful brand/proper-noun capitalization.
- [x] Run focused express-settings Jest until GREEN.

## Task 6: Browser, Logs, Reviews, And Commit

- [x] Run exact-file ESLint for touched TS/TSX files, PHP syntax for touched PHP, changed-file PHPCS, production PHPStan for `WooPaymentsSettingsService.php`, admin TypeScript lint, and admin bundle build.
- [x] Use Playwriter on the target store to smoke `/woopayments/settings`, `/woopayments/settings/express-checkout/payment_request`, `/woopayments/settings/express-checkout/woopay`, and `/woopayments/settings/express-checkout/amazon_pay`; capture JSON/screenshot evidence under `data/a4ax-express-settings-parity/`.
- [x] Scan target `debug.log` and recent target Docker logs for PHP/WP notices, warnings, deprecations, fatals, database errors, stack traces, and actual 5xx markers.
- [x] Dispatch focused review agents: architecture/API contract for the settings contract, a11y for linked notices/help text and detail pages, JS-test review for the RED/GREEN coverage, and code/reliability review for the full diff.
- [x] Add the WooCommerce changelog entry, run changelog validation, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch`.
- [x] Update `implementation-log.md`, `staging-log.md`, `README.md`, and this plan to final status, then commit source/tests and changelog locally if all gates pass. Do not push.

## Deferred Checkpoints

- [ ] After H31 is cleanly closed, check the N8 supervisor advisory in `supervisor-prompt-2026-06-17-1311.md`.
- [ ] Once A5c is fully done, reopen A4 for feature parity according to `supervisor-prompt-2026-06-18-2344-N12.md`.
