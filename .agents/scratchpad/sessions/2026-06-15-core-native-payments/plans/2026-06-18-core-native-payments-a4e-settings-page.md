# A4e Native WooPayments Settings Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the A4a WooPayments account-summary stub with a native Core-owned WooPayments settings page that preserves the reference settings UX and REST contract without carrying deprecated Stripe Billing or a parallel WooPayments plugin runtime.

**Architecture:** Hoist the reference WooPayments settings module as a unit: settings REST contract, `wc/payments/settings` data store, settings components, and settings SCSS. Mount it through WooCommerce Settings > Payments provider routes under `/woopayments/settings`, and keep the current `section=woocommerce_payments` shell as a compatibility landing point that renders or redirects to the same native settings surface. Cut plugin-runtime edges by adapting imports into `~/woopayments/settings` and Core-owned services rather than introducing a broad `wcpay/*` alias.

**Tech Stack:** WooCommerce Core PHP REST controllers/services, `@wordpress/data`, React, WooCommerce Admin webpack lazy chunks, SCSS, PHPUnit, Jest/RTL, Playwriter browser verification.

---

## Files And Responsibilities

- Backend create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` as the Core-native settings domain service for reading and saving WooPayments gateway settings, account-backed settings, fraud settings, WooPay settings, express checkout settings, allowed option updates, and file upload delegation.
- Backend modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php` to register the native settings endpoints while leaving onboarding/account-summary routes intact.
- Backend modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php` or the WooPayments provider class only where needed to expose the settings URL as `Utils::wc_payments_settings_url( '/woopayments/settings', ... )`.
- Backend test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php` and focused additions to the existing WooPayments REST controller tests if they already exist.
- Frontend create: `plugins/woocommerce/client/admin/client/woopayments/settings/data/` for the Core-owned `wc/payments/settings` store copied/adapted from the reference `client/data/settings`.
- Frontend create/modify: `plugins/woocommerce/client/admin/client/woopayments/settings/bootstrap.ts`, `settings-page.tsx`, and `index.ts` to provide the localized settings context and export the routed settings page.
- Frontend modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` to register `/woopayments/settings` as a lazy settings chunk.
- Frontend modify: `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.tsx` to render the same native settings page for the legacy `section=woocommerce_payments` shell, or redirect cleanly to the route without mixing `section=` and `path=`.
- Frontend create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-manager/`, `general-settings/`, `payment-methods-section/`, `payment-methods-list/`, `express-checkout/`, `express-checkout-settings/`, `transactions/`, `deposits/`, `notification-settings/`, `fraud-protection/`, `advanced-settings/`, and shared thin wrappers needed by those sections.
- Frontend create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss` and local partials/assets needed by the settings page, loaded only by the settings chunk.
- Changelog add: a WooCommerce Core changelog entry for the A4e merchant settings surface.
- Scratchpad update: `analysis-a4e-settings-page.md`, `implementation-log.md`, and `staging-log.md` with the final hoist decision, gates, and known follow-ups.

## Task 1: Backend Settings Contract

**Files:**
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`

- [ ] **Step 1: Write failing REST/service tests for the compact settings contract**

Create focused tests that assert `GET /wc/v3/payments/settings` returns the reference-shaped keys needed by the hoisted store, `POST /wc/v3/payments/settings` persists gateway options and returns the refreshed settings object, `POST /wc/v3/payments/settings/{option}` accepts only the existing allowlist, and Stripe Billing keys/endpoints are absent or inert under native. Include at least these keys in the read contract: `enabled_payment_method_ids`, `available_payment_method_ids`, `payment_method_statuses`, `duplicated_payment_method_ids`, `is_wcpay_enabled`, `is_manual_capture_enabled`, `is_test_mode_enabled`, `is_test_mode_onboarding`, `is_dev_mode_enabled`, `is_multi_currency_enabled`, `is_wcpay_subscriptions_enabled`, `is_wcpay_subscriptions_eligible`, `is_subscriptions_plugin_active`, `account_country`, `account_statement_descriptor`, `account_business_name`, `account_business_url`, `account_business_support_address`, `account_business_support_email`, `account_business_support_phone`, `account_branding_logo`, `account_branding_icon`, `account_branding_primary_color`, `account_branding_secondary_color`, `account_domestic_currency`, `account_communications_email`, `is_payment_request_enabled`, `is_express_checkout_in_payment_methods_enabled`, `is_debug_log_enabled`, `payment_request_button_size`, `payment_request_button_type`, `payment_request_button_theme`, `payment_request_button_border_radius`, `is_saved_cards_enabled`, `is_card_present_eligible`, `is_woopay_enabled`, `show_woopay_incompatibility_notice`, `woopay_custom_message`, `woopay_store_logo`, `deposit_schedule_interval`, `deposit_schedule_monthly_anchor`, `deposit_schedule_weekly_anchor`, `deposit_delay_days`, `deposit_status`, `deposit_restrictions`, `deposit_completed_waiting_period`, `current_protection_level`, `advanced_fraud_protection_settings`, `express_checkout_product_methods`, `express_checkout_cart_methods`, and `express_checkout_checkout_methods`.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php -- --filter WooPaymentsSettingsServiceTest`

Expected: fail because the native settings service/controller does not exist yet.

- [ ] **Step 2: Implement the native settings service**

Build a service that reads and writes the preserved native WooPayments gateway option (`woocommerce_woocommerce_payments_settings`) through existing Core proxy/account service seams, not through the separate plugin. Mirror the reference mapping where it is still active: `manual_capture`, `test_mode`, `enable_logging`, `saved_cards`, `payment_request_button_*`, `platform_checkout`, `platform_checkout_custom_message`, `platform_checkout_store_logo`, `express_checkout_*_methods`, `upe_enabled_payment_method_ids`, and account-facing settings. Route live account changes through `WooPaymentsApiClient` only where the reference updates account settings, and fail closed with a `WP_Error` when the provider call fails.

Do not implement or register `schedule-stripe-billing-migration`, do not write `_wcpay_feature_stripe_billing`, and do not delete legacy Stripe Billing merchant data. Keep multi-currency at the boolean toggle only; the full multi-currency settings UI stays outside this slice.

- [ ] **Step 3: Register REST routes and provider settings URL**

Register compatibility endpoints under `/wc/v3/payments/settings`, `/wc/v3/payments/settings/(?P<option_name>[a-zA-Z0-9_-]+)`, and `/wc/v3/payments/file` because the hoisted store and WooPay logo upload use that contract. Wire permission callbacks to the same capability level used by existing WooCommerce payments settings routes. Make the WooPayments provider settings URL resolve to `admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings&from=WCADMIN_PAYMENT_SETTINGS`, avoiding `section=` on routed URLs.

- [ ] **Step 4: Prove backend green**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php -- --filter 'WooPaymentsSettingsServiceTest|WooPaymentsRestControllerTest|WooPaymentsAccountServiceTest'`

Run PHP lint/PHPStan on the touched production files from `plugins/woocommerce`: `composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php --memory-limit=2G`

Expected: focused tests pass, no new PHPStan issue, no WordPress notices from the endpoint probe.

## Task 2: Frontend Store, Bootstrap, Route, And Build Wiring

**Files:**
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/data/*`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/bootstrap.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/index.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.tsx`
- Test: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`

- [ ] **Step 1: Write failing Jest tests for store registration and route ownership**

Assert that the settings data store registers as `wc/payments/settings`, fetches from `/wc/v3/payments/settings`, saves with `POST /wc/v3/payments/settings`, saves allowlisted options through `/wc/v3/payments/settings/{option}`, and never calls `/settings/schedule-stripe-billing-migration`. Assert that `/woopayments/settings` is registered as a provider route with its own lazy chunk and that the legacy WooPayments settings wrapper renders the same native settings page without combining `section` and `path`.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`

Expected: fail because the store and route do not exist yet.

- [ ] **Step 2: Hoist and adapt the settings data store**

Copy the reference store shape from `woocommerce-payments/client/data/settings` into the Core WooPayments namespace and adapt imports to local Core paths. Keep the public store name `wc/payments/settings` so hook-bound settings components work. Remove Stripe Billing actions/selectors/hooks from the native store rather than keeping dead exports. Replace `woocommerce-payments` text domain strings with `woocommerce` only where strings live in Core-owned code.

- [ ] **Step 3: Add a Core-owned settings bootstrap adapter**

Expose a typed `getWooPaymentsSettingsBootstrap()` helper that reads a Core-localized payload from `window.wcSettings.admin.woopaymentsSettings` or a similarly Core-owned key. Do not introduce `window.wcpaySettings` as the canonical runtime dependency. The adapter may set a temporary compatibility object for hoisted code only inside the settings chunk, but all new Core code should call the adapter.

- [ ] **Step 4: Register the settings route as a lazy chunk**

Add a lazy `settings-payments-woopayments-settings` chunk in `woopayments/admin/routes.tsx` and register it at `/woopayments/settings` with an order before the overview dashboard. Update `SettingsPaymentsWoopayments` to render the native settings page or route-compatible wrapper. Keep the chunk under the existing `settings-embed` entry so WooCommerce Admin build workflows own it naturally.

- [ ] **Step 5: Prove frontend wiring green**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`

Run: `pnpm --filter=@woocommerce/plugin-woocommerce build:admin`

Expected: the settings chunk is emitted separately, routes are registered once, and no broad plugin alias is added to webpack.

## Task 3: Settings Manager Hoist And Edge Adaptation

**Files:**
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-manager/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/general-settings/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-section/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout-settings/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/transactions/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/deposits/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/notification-settings/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/*`
- Create/adapt: `plugins/woocommerce/client/admin/client/woopayments/settings/advanced-settings/*`
- Create/adapt: local wrappers/assets under `plugins/woocommerce/client/admin/client/woopayments/settings/components/*` only for wrappers that do not have a clean Core equivalent.
- Test: focused RTL tests for settings page section rendering and save behavior.

- [ ] **Step 1: Write failing rendering and save-flow tests**

Render the native settings page with a mocked settings store payload and assert that general settings, payment methods, express checkout, transactions/manual capture, deposits, fraud protection, notification settings, and advanced settings render. Assert that the save footer dispatches the hoisted store `saveSettings()` action and shows the success/error notices through the Core notices store.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

Expected: fail because the full manager has not been hoisted.

- [ ] **Step 2: Hoist settings sections as a unit and cut excluded surfaces**

Copy/adapt the reference `SettingsManager` and the listed sections. Exclude `advanced-settings/stripe-billing-section.tsx`, `stripe-billing-toggle.tsx`, `stripe-billing-notices/`, `updateIsStripeBillingEnabled`, `submitStripeBillingSubscriptionMigration`, and related selectors/hooks. Keep the multi-currency toggle but do not port the multi-currency configuration UI. Replace promotion and VAT modal imports with no-op/native-safe components only if source verification shows they are optional; otherwise create Core-owned adapters that preserve visible UX without plugin runtime imports.

- [ ] **Step 3: Adapt shared wrappers and assets locally**

Bring only the thin wrappers required by the settings page into `woopayments/settings/components`, including inline notice, card body, chip, pill, confirmation modal, loadable, amount input, payment-method item/logos, and file upload if no clean Core equivalent exists. Copy payment method SVG assets used by the settings page into a Core-owned WooPayments asset location or reuse existing Core assets when exact. Do not add a top-level `wcpay/*` alias.

- [ ] **Step 4: Adapt settings SCSS into the lazy settings chunk**

Import settings SCSS only from the settings page chunk. Bring required reference abstract tokens into local partials if needed for fidelity, but scope selectors under the WooPayments settings root where possible. Verify that WooPayments settings CSS is not loaded when the merchant is only viewing the provider list or another payment provider's settings.

- [ ] **Step 5: Prove component green**

Run focused Jest tests, targeted TypeScript checks if available for the admin client, and `pnpm --filter=@woocommerce/plugin-woocommerce build:admin`. Inspect emitted assets to confirm the settings code sits in a dedicated lazy chunk and does not inflate the base settings embed beyond the measured threshold recorded for A4.

## Task 4: Integrated Browser, Harness, And Review Gate

**Files:**
- Modify: `plugins/woocommerce/changelog/*`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md` only if A4e changes an existing baseline claim.

- [ ] **Step 1: Add changelog and run source gates**

Add a WooCommerce Core changelog entry. Run focused PHP/Jest tests, PHP lint/PHPStan for touched PHP files, ESLint/TypeScript for touched frontend files, `git diff --check`, and changelog validation. Do not lint scratchpad files.

- [ ] **Step 2: Run browser parity checks with Playwriter**

On the native target `http://store8889.localhost:8889`, load `admin.php?page=wc-settings&tab=checkout`, confirm the WooPayments provider Manage button goes to `/woopayments/settings`, then inspect the native settings page. Compare with the reference store `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments`. Verify visible parity for general settings, payment methods, express checkout, transaction/manual capture, deposits, fraud protection, notifications, advanced settings, save/error states, and responsive behavior. Record screenshots and REST payload evidence under the session `data/` directory.

- [ ] **Step 3: Run local harness gates**

Run `tools/woopayments-merge/verify.sh` plus the individual gates that touch admin routes and settings. If bundle/perf measurements are variable, record them honestly as stop-gap indicators and only fail on material regressions or unexercised requirements. Do not access WPCOM sandbox or modify WPCOM code.

- [ ] **Step 4: Run review subagents**

Dispatch at least an API contract reviewer, architecture reviewer, accessibility reviewer, performance reviewer, and reliability reviewer over the A4e diff. Treat source-backed blockers as fail-closed. Fix findings before committing.

- [ ] **Step 5: Commit A4e**

Commit one logical A4e change when all gates are green. Record the git range and update the staging log with the hoist decision, verification evidence, residual risks, and A4 exit implications for N10 bundle/runtime measurement.

---

## Self-Review

Spec coverage: The plan covers N11's hoist-vs-rebuild decision, the native provider-route placement requested by the user, the REST/store coupling that makes settings a unit, the Stripe Billing exclusion required by N9, the multi-currency separation, Core-owned build workflow requirements, and fail-closed verification gates. It does not claim full A4 completion until browser, harness, bundle, and review gates run.

Placeholder scan: There are no TODO/TBD placeholders. The implementation details that must be source-verified are explicitly marked as verification decisions with expected behavior, not open blanks.

Type and path consistency: Backend paths use the existing native WooPayments namespace. Frontend paths live under `~/woopayments/settings` and the route under `/woopayments/settings`, matching the existing provider route seam.

