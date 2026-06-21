---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 04:41
last_updated: 2026-06-20 05:19
status: complete
reconciles:
  - analysis-a4ag-next-parity-slice.md
  - supervisor-prompt-2026-06-18-2344-N12.md
---

# A4ag Settings Merchant Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the next native WooPayments settings-page parity bundle: manual-capture confirmation, failed-save focus, express overview legal/status/duplicate parity, and related settings-data error handling.

**Architecture:** Keep the settings page owned by the native provider route and keep the data hooks on the existing Core registry. Add only the small helpers needed to make the rebuilt native settings page match the reference contracts; do not introduce a separate settings runtime or re-enable Stripe Billing. Backend duplicate detection stays inside `WooPaymentsSettingsService` and remains fail-closed.

**Tech Stack:** React/TypeScript, `@wordpress/components`, WooCommerce admin Jest/RTL tests, WooCommerce PHP unit tests, native WooPayments REST settings service.

---

## Files

- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/data/actions.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`
- Possibly modify: `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss` if modal or notice spacing needs a scoped adjustment.

## Task 1: RED Tests For Settings Parity

- [x] Add settings-page tests proving manual capture does not enable until the confirmation modal is accepted, disabling still applies immediately, failed save scrolls/focuses the first server-detail field, express rows render legal/read-more links, Amazon Pay is disabled with a status notice when inactive, and Apple/Google Pay renders a duplicate notice from `apple_pay_google_pay`.
- [x] Add a settings-data test proving `saveSettings()` does not emit the raw `server_error` notice when the failed REST error includes `data.details`.
- [x] Add a PHP settings-service test proving Apple/Google/payment-request duplicate gateways produce `duplicated_payment_method_ids['apple_pay_google_pay']` only when the cluster includes native WooPayments payment request plus another enabled gateway.
- [x] Run the targeted Jest and PHPUnit tests to verify the new tests fail for the expected missing behavior.

## Task 2: Implement Frontend Settings Parity

- [x] Add a manual-capture confirmation modal in `settings-page.tsx` using native `Modal` and `Button` components. Enabling opens the modal and only confirms to true; disabling updates immediately.
- [x] Add deterministic ids for server-error-backed fields, plus a helper that maps `error.data.details` keys to those ids, scrolls the first matching input into view, respects reduced motion, and focuses with `preventScroll`.
- [x] Wire the failed-save helper into `SaveSettingsSection` immediately after unsuccessful `saveSettings()`.
- [x] Allow express overview descriptions/notices to be `ReactNode`, then render reference-equivalent legal/read-more links for WooPay, Apple/Google Pay, Link, and Amazon Pay.
- [x] Reuse the existing settings-row availability and duplicate-notice patterns where possible so Amazon Pay status notices and Apple/Google duplicate notices match the rest of native settings.
- [x] Run the targeted settings-page Jest test until green.

## Task 3: Implement Data And Backend Contracts

- [x] Update `saveSettings()` so field-detail validation failures keep the generic save notice and field-level UI, but suppress the second raw `server_error` notice when details are present.
- [x] Extend `WooPaymentsSettingsService` duplicate detection with a synthetic `apple_pay_google_pay` cluster for native WooPayments payment request and enabled third-party Apple Pay / Google Pay / payment-request gateways.
- [x] Keep duplicate detection wrapped in the existing fail-closed catch path and avoid deleting or mutating merchant data.
- [x] Run targeted settings-data Jest and `WooPaymentsSettingsServiceTest` until green.

## Task 4: Verification, Browser Proof, And Docs

- [x] Run focused JS/PHP tests, TypeScript or eslint checks for changed admin files, PHP syntax/PHPCS/PHPStan for changed PHP, and branch diff hygiene excluding `.agents`.
- [x] Use Playwriter against the target store to verify the settings page shows the manual-capture confirmation modal, field-error focus behavior, express legal links, Amazon disabled notice, and Apple/Google duplicate notice when test fixtures make those states available.
- [x] Compare the same relevant settings states against the reference store where deterministic.
- [x] Clear and then scan target debug logs for new PHP notices/warnings/errors.
- [x] Update `analysis-a4ag-next-parity-slice.md`, `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with results and any deferred follow-up slices.
- [x] Commit one logical product change and one changelog/docs-only change if needed, without pushing.
