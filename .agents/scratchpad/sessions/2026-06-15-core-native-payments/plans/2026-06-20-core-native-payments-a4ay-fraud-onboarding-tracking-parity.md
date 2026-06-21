---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 22:55
target: A4ay fraud onboarding and tracking parity
reconciles:
  - analysis-a4ay-fraud-onboarding-tracking-parity.md
  - review-a4au-settings-inventory.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4ay Fraud Onboarding And Tracking Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments fraud-protection onboarding tour and Tracks continuity while preserving the already-built Basic/Advanced fraud UI and advanced subpage.

**Architecture:** Keep the dismissed-tour state in the existing native WooPayments settings REST contract under `fraud_protection`, and consume it from the existing settings data store. Add a small native fraud tour component inside the WooPayments settings chunk, using Core's existing `TourKit`, `saveOption()`, and `@woocommerce/tracks` instead of reintroducing plugin-global `wcpaySettings`.

**Tech Stack:** PHP settings service + PHPUnit, React/TypeScript, WordPress/WooCommerce components, WooCommerce Tracks, Jest/RTL, Playwriter for browser proof.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` to include fraud welcome-tour dismissed state in the settings response.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php` to cover the new fraud contract field.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx` to add fraud-level Tracks events and render the tour.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/tour.tsx` for the native TourKit implementation and tour steps.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx` to add advanced fraud settings Tracks events.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx` for main settings/tour/Tracks coverage.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx` for advanced save and card-view Tracks coverage.
- Add `plugins/woocommerce/changelog/fix-native-payments-a4ay-fraud-onboarding-tracking`.
- Update `analysis-a4ay-fraud-onboarding-tracking-parity.md`, `implementation-log.md`, `staging-log.md`, `README.md`, and this plan as the slice progresses.

## Task 1: RED Contract And Frontend Tests

- [ ] Add `WooPaymentsSettingsServiceTest` coverage proving `get_settings()['fraud_protection']['is_welcome_tour_dismissed']` is false by default and true when `wcpay_fraud_protection_welcome_tour_dismissed` is true.
- [ ] Add `settings-page.test.tsx` coverage that selecting Basic/Advanced records `wcpay_fraud_protection_risk_level_preset_enabled` with `{ preset }`.
- [ ] Add `settings-page.test.tsx` coverage that opening the Basic fraud modal records `wcpay_fraud_protection_basic_modal_viewed`.
- [ ] Add `settings-page.test.tsx` coverage that the fraud tour renders after the fraud settings section intersects when `fraud_protection.is_welcome_tour_dismissed=false`, does not render when true, persists `wcpay_fraud_protection_welcome_tour_dismissed`, and records clicked-through vs abandoned events.
- [ ] Add `fraud-protection-advanced.test.tsx` coverage that saving advanced settings records `wcpay_fraud_protection_advanced_settings_saved` with the serialized ruleset.
- [ ] Add `fraud-protection-advanced.test.tsx` coverage that intersecting advanced rule cards records the reference card-view event once and unobserves the element.
- [ ] Run RED commands and record failures: focused PHPUnit `WooPaymentsSettingsServiceTest`, focused Jest `settings-page.test.tsx`, and focused Jest `fraud-protection-advanced.test.tsx`.

## Task 2: Settings Contract Implementation

- [ ] Add `is_welcome_tour_dismissed` to the array returned by `get_fraud_protection_settings()` in `WooPaymentsSettingsService.php`, sourced from `get_option( 'wcpay_fraud_protection_welcome_tour_dismissed', false )`.
- [ ] Keep the existing `update_option()` allowlist unchanged except for tests; do not add a new endpoint or a plugin-global preload.
- [ ] Run focused `WooPaymentsSettingsServiceTest` until GREEN.

## Task 3: Main Fraud Settings Tour And Tracks

- [ ] Import `recordEvent` from `@woocommerce/tracks` in `fraud-protection/index.tsx` and record `wcpay_fraud_protection_risk_level_preset_enabled` before updating the selected level.
- [ ] Record `wcpay_fraud_protection_basic_modal_viewed` before opening the Basic help modal.
- [ ] Create `fraud-protection/tour.tsx` using `TourKit`, `saveOption()`, `useSettings()`, and `useGetSettings()`; target native selectors `#fraud-protection` and `.woopayments-fraud-protection-levels`, use reference-equivalent headings/descriptions, save the dismissed option on close, and record `wcpay_fraud_protection_tour_clicked_through` when the close element is `done-btn`, otherwise `wcpay_fraud_protection_tour_abandoned`.
- [ ] Render the tour from `FraudProtectionSettings` without changing the existing controls or advanced route.
- [ ] Run focused `settings-page.test.tsx` until GREEN.

## Task 4: Advanced Fraud Settings Tracks

- [ ] Import `recordEvent` from `@woocommerce/tracks` in `fraud-protection/advanced/index.tsx`.
- [ ] Add a reference-compatible card-view event map for the current native rule-card IDs, preserving the plugin event names.
- [ ] Observe cards after loading completes, record each event once when a card becomes visible, and disconnect the observer on cleanup. If `IntersectionObserver` is unavailable, skip tracking without breaking the page.
- [ ] Record `wcpay_fraud_protection_advanced_settings_saved` after a valid advanced settings save attempt with `settings: JSON.stringify( writeRuleset( ... ) )`, matching reference behavior.
- [ ] Run focused `fraud-protection-advanced.test.tsx` until GREEN.

## Task 5: Static, Browser, Reviews, And Commit

- [ ] Run exact-file ESLint for touched TS/TSX, PHP syntax for touched PHP files, changed-file PHPCS, production PHPStan for `WooPaymentsSettingsService.php`, admin TypeScript lint, and admin bundle build.
- [ ] Use Playwriter on the target store to smoke `/woopayments/settings` and `/woopayments/settings/fraud-protection`; capture JSON/screenshot evidence under `data/a4ay-fraud-onboarding-tracking/`.
- [ ] Scan target `debug.log` and recent target Docker logs for PHP/WP notices, warnings, deprecations, fatals, database errors, stack traces, and actual 5xx markers.
- [ ] Dispatch focused review agents for Tracks/API contract, accessibility/tour behavior, JS tests, and code/reliability. Reconcile findings with source-backed fixes.
- [ ] Add the WooCommerce changelog entry, run changelog validation, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch`.
- [ ] Update `analysis-a4ay-fraud-onboarding-tracking-parity.md`, `implementation-log.md`, `staging-log.md`, `README.md`, and this plan to final status, then commit source/tests and changelog locally if all gates pass. Do not push.

## Deferred Checkpoints

- [ ] After H31 is cleanly closed, check the N8 supervisor advisory in `supervisor-prompt-2026-06-17-1311.md`.
- [ ] Once A5c is fully done, reopen A4 for feature parity according to `supervisor-prompt-2026-06-18-2344-N12.md`.
