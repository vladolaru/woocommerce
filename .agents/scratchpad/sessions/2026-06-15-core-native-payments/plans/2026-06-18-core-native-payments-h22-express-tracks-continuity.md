---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 02:48
target: H22 non-WooPay express checkout Tracks continuity
reconciles:
  - spec-conformance-baseline.md
  - review-agent-findings.md
  - plans/2026-06-17-core-native-payments-h16-tracks-continuity.md
  - plans/2026-06-17-core-native-payments-h18-express-checkout.md
  - plans/2026-06-17-core-native-payments-h19-remaining-express-checkout.md
  - plans/2026-06-18-core-native-payments-h20-product-page-express-checkout.md
status: implemented
---

# Express Tracks Continuity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the H16 residual Tracks continuity gap for native non-WooPay Express Checkout surfaces now that H18-H20 own Apple Pay, Google Pay, Amazon Pay, order-pay, and product-page ECE in Core.

**Architecture:** Keep shopper telemetry on the provider-owned `platform_tracks` bridge introduced in H16, with server-side `wcpay_` prefixing and `record_event_data.track_on_all_stores` for Apple Pay and Google Pay events. Add only WooPayments express-checkout bundle code; do not route shopper events through Core admin Tracks or load tracking helpers outside the WooPayments express assets.

**Tech Stack:** WooCommerce Core native WooPayments PHP, classic frontend JS/Jest, Blocks React/Jest, PHPUnit, normal WooCommerce classic and Blocks build workflows, local browser or Playwriter/Playwright proof when deterministic JS tests cannot prove a browser request shape.

**Implementation status, 2026-06-18 03:00 EEST:** Implemented locally with RED/GREEN PHP/classic/Blocks coverage, normal classic and Blocks builds, targeted JS lint, changed-file PHP lint, source PHPStan, branch lint, and `git diff --check`. Chrome DevTools MCP timed out on `list_pages`, so no live wallet browser request capture is claimed for H22.

---

## Source Evidence

- Reference WooPayments emits `applepay_button_load`, `gpay_button_load`, `applepay_button_click`, and `gpay_button_click` from `client/express-checkout/tracking.js` through `recordUserEvent()`, which posts to `platform_tracks`.
- Reference ready handling derives events from truthy entries in `availablePaymentMethods`; click handling uses `event.expressPaymentType`, so a Google Pay click emits only `gpay_button_click`.
- Reference `WC_Payments_WooPay_Tracker::add_tracking_config_to_express_checkout()` adds `is_shopper_tracking_enabled` to `wcpay_express_checkout_js_params`.
- Native `WooPaymentsExpressCheckoutController::add_tracking_event_properties()` already marks `wcpay_applepay_*` and `wcpay_gpay_*` events as all-store events.
- Native Blocks express checkout already has method-specific `loadEvent`/`clickEvent` metadata and posts via `recordWooPaymentsUserEvent()`, but ready handling should still prove it does not emit when the method is not actually available and it should honor the tracking-enabled flag from express params.
- Native classic express checkout currently records both Apple Pay and Google Pay load/click events for any ready/click event, so it can over-report the reference contract.

## Files

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php` to expose `is_shopper_tracking_enabled` in express checkout params through `WooPaymentsFrontendTrackingController`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutServiceTest.php` to assert the flag is present and follows the tracking controller.
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-express-checkout.js` to derive load events from `event.availablePaymentMethods` and click events from `event.expressPaymentType`.
- Modify: `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-express-checkout.js` to add RED coverage for method-specific load/click events and frontend disabled-tracking no-op behavior.
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/index.js` to check the current method in ready events before recording load, and to pass the express params tracking flag into `recordWooPaymentsUserEvent()`.
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/test/index.js` to add RED coverage for method-specific Blocks load/click behavior and disabled-tracking no-op.
- Add: `plugins/woocommerce/changelog/fix-native-woopayments-express-tracks-continuity`.
- Update: `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `review-agent-findings.md`.

## Tasks

### Task 1: RED Tracking Config Coverage

- [ ] Add a focused PHPUnit assertion in `WooPaymentsExpressCheckoutServiceTest::test_builds_payment_request_express_checkout_params()` that expects both `is_shopper_tracking_enabled` and `isShopperTrackingEnabled` to be present and `true` by default, mirroring the H16 card/WooPay config shape while preserving the reference snake-case key.
- [ ] Add a second focused PHPUnit test that injects a `WooPaymentsFrontendTrackingController` mock returning `false` and expects both tracking flags to be `false`.
- [ ] Run `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsExpressCheckoutServiceTest::test_builds_payment_request_express_checkout_params` and the new disabled-tracking test. Expected RED: native express params currently have no tracking-enabled flag.

### Task 2: GREEN Tracking Config Plumbing

- [ ] Update `WooPaymentsExpressCheckoutService` to accept/use `WooPaymentsFrontendTrackingController`, using the same lazy container fallback pattern already used by `WooPaymentsCheckoutBridge` and `WooPaymentsWooPaySessionService` if direct constructor changes would ripple too widely.
- [ ] Add both `is_shopper_tracking_enabled` and `isShopperTrackingEnabled` to `get_express_checkout_params()`, sourced from `WooPaymentsFrontendTrackingController::is_shopper_tracking_enabled()`.
- [ ] Re-run the focused PHPUnit tests and keep PHPStan in mind for the new dependency shape.

### Task 3: RED Classic Express Tracks Coverage

- [ ] Add classic Jest tests that initialize the ECE with `availablePaymentMethods: { applePay: true, googlePay: false }` and assert only one `platform_tracks` request with `tracksEventName=applepay_button_load`.
- [ ] Add a classic Jest test that triggers click with `{ expressPaymentType: 'google_pay' }` and asserts only `gpay_button_click` is sent with `{ source: 'checkout' }`.
- [ ] Add a classic Jest test that sets `window.wcpayExpressCheckoutParams.is_shopper_tracking_enabled = false`, triggers ready/click, and asserts no `platform_tracks` fetch happens.
- [ ] Run `pnpm --filter=@woocommerce/classic-assets test:js -- frontend/test/woopayments-express-checkout.js`. Expected RED: load/click currently over-record both Apple Pay and Google Pay, and the config flag is not present in service output.

### Task 4: GREEN Classic Express Tracks

- [ ] Add a small helper map in `woopayments-express-checkout.js` for Stripe ECE names: `applePay -> applepay_button_load`, `googlePay -> gpay_button_load`, `apple_pay -> applepay_button_click`, `google_pay -> gpay_button_click`.
- [ ] Update the ready handler to iterate truthy `event.availablePaymentMethods` entries and record only mapped Apple Pay / Google Pay load events.
- [ ] Update the click handler to record only the mapped event for `event.expressPaymentType`; do not record Amazon Pay because the reference contract has no Amazon event in the current hard-preserve set.
- [ ] Keep the existing `recordUserEvent()` no-op guard for `is_shopper_tracking_enabled === false`.
- [ ] Re-run the focused classic Jest suite until GREEN.

### Task 5: RED/GREEN Blocks Express Tracks Coverage

- [ ] Add Blocks Jest coverage that renders the Apple Pay registration, fires `ready` with `{ availablePaymentMethods: { applePay: false, googlePay: true } }`, and asserts no Apple Pay load tracking request is sent.
- [ ] Add Blocks Jest coverage that renders the Google Pay registration, fires `ready` and `click`, and asserts `gpay_button_load` and `gpay_button_click` are posted with `source: checkout`.
- [ ] Add Blocks Jest coverage that sets `expressCheckoutParams.is_shopper_tracking_enabled = false` or `isShopperTrackingEnabled = false`, then asserts no `platform_tracks` request is sent.
- [ ] Implement the minimal Blocks fix: ready should check `event.availablePaymentMethods?.[ method ]` before recording load, and the event helper settings object should include the express tracking-enabled flags from `params`.
- [ ] Run `pnpm --filter=@woocommerce/block-library test:js --runTestsByPath assets/js/extensions/payment-methods/woopayments/express-checkout/test/index.js` until GREEN.

### Task 6: Verification, Browser Smoke, Reviews, Docs, Commit

- [ ] Run focused PHPUnit for `WooPaymentsExpressCheckoutServiceTest|WooPaymentsExpressCheckoutControllerTest|WooPaymentsFrontendTrackingControllerTest`.
- [ ] Run focused JS for classic and Blocks express checkout.
- [ ] Run PHP syntax on touched PHP, source-only PHPStan for touched production PHP, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, targeted JS lint for changed express checkout files, normal `@woocommerce/classic-assets` and `@woocommerce/block-library` builds, and `git diff --check`.
- [ ] Use browser automation if practical to capture a target Express Checkout `platform_tracks` request from a live ECE surface; if local Stripe wallet availability makes this unreliable, record deterministic Jest/PHP evidence and do not overstate browser proof.
- [ ] Ask subagents for focused API/telemetry contract and code-quality reviews of the final diff; source-verify any findings before acting.
- [ ] Add the changelog entry, commit source/tests and changelog separately, update session docs with exact commands, residual scope, and git range.

## Self-Review

- Spec coverage: H22 addresses the H16 residual non-WooPay express Tracks scope for Apple Pay and Google Pay after H18-H20 implemented the native ECE surfaces. It does not invent Amazon Pay Tracks because the reference hard-preserve event set found so far only covers Apple Pay and Google Pay.
- Architecture check: the plan keeps the provider-local `platform_tracks` bridge and does not add Core admin Tracks dependencies to shopper code.
- Bundle/perf check: changes stay inside existing WooPayments express checkout bundles and PHP config; no new frontend entrypoint is planned.
