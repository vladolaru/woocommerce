---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 04:07
reconciles:
  - analysis-a4af-settings-woopay-residual-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: complete
---

# A4af WooPay Disable Feedback Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the reference WooPay disable-feedback contract in native WooPayments settings.

**Architecture:** Keep ownership inside the existing native WooPayments settings service and Core Settings > Payments React route. Persist the WooPay last-disable date in the same gateway settings array used by native WooPay settings, return it in the settings contract, and render a scoped native modal after a successful save disables WooPay when the seven-day throttle allows it.

**Tech Stack:** WooCommerce Core PHP service/tests, WooCommerce admin React/TypeScript, WordPress Components Modal/Spinner, React Testing Library, Playwriter browser proof.

---

## Files

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` to expose `woopay_last_disable_date` and persist `platform_checkout_last_disable_date` when WooPay transitions from enabled to disabled.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php` for RED/GREEN settings contract and disable-date persistence tests.
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/woopay-disable-feedback.tsx` for the scoped modal.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` so `SaveSettingsSection` tracks initial/current WooPay state, successful saves, and throttle behavior.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss` for modal layout.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx` for RED/GREEN modal, failure, and throttle coverage.
- Modify: session scratchpad logs at closeout only.

## Task 1: Backend Disable-Date Contract

- [x] Add a failing PHPUnit test that `get_settings()` includes `woopay_last_disable_date` when `platform_checkout_last_disable_date` exists in `woocommerce_woocommerce_payments_settings`.
- [x] Add a failing PHPUnit test that `update_settings( [ 'is_woopay_enabled' => false ] )` changes `platform_checkout` from `yes` to `no`, writes `platform_checkout_last_disable_date` to `gmdate( 'Y-m-d' )`, and returns `woopay_last_disable_date` in the refreshed response.
- [x] Add a negative PHPUnit assertion that an already-disabled WooPay setting does not overwrite an existing `platform_checkout_last_disable_date`.
- [x] Run `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsSettingsServiceTest` and confirm the new assertions fail for the expected missing contract/side-effect reasons.
- [x] Update `WooPaymentsSettingsService::get_settings()` to return `woopay_last_disable_date`.
- [x] Update `WooPaymentsSettingsService::update_settings()` to capture the previous `platform_checkout` state before local setting normalization and write today's date only when the request disables WooPay from a previously enabled state.
- [x] Re-run the focused PHPUnit test and confirm it passes.

## Task 2: Frontend Disable-Feedback Modal

- [x] Add failing React tests in `settings-page.test.tsx` covering: successful save after WooPay is changed from enabled to disabled opens the feedback modal; failed save does not open it; a last-disable date inside the previous seven days suppresses it.
- [x] Run the focused settings Jest file and confirm the new tests fail because no modal exists.
- [x] Create `woopay-disable-feedback.tsx` using `Modal`, the existing WooPay preview logo asset, a polite loading status, and an iframe titled `WooPay disable feedback` with the reference survey URL.
- [x] Update `SaveSettingsSection` to read `is_woopay_enabled` and `woopay_last_disable_date` from the settings contract, remember the first loaded WooPay enabled state, and open `WooPayDisableFeedback` only after `saveSettings()` resolves truthy and the reference-style seven-day throttle permits it.
- [x] Add scoped modal styles to `settings/style.scss`.
- [x] Re-run the focused settings Jest file and confirm it passes.

## Task 3: Verification and Closeout

- [x] Run targeted ESLint for `settings-page.tsx`, `woopay-disable-feedback.tsx`, and `settings-page.test.tsx`.
- [x] Run PHP syntax and PHPStan for `WooPaymentsSettingsService.php`, plus changed-file PHPCS.
- [x] Run admin `lint:lang:types`, admin `lint:lang:css`, and admin `build:project:bundle`.
- [x] Use Playwriter on the target store settings route to confirm the WooPayments settings page still loads and the relevant WooPay/settings UI is reachable without browser console errors. If the local account state makes deterministic modal triggering impractical through the browser, record that honestly and rely on RED/GREEN unit coverage for modal triggering.
- [x] Scan target logs for PHP notices/warnings after the browser proof.
- [x] Run changelog validation, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch`.
- [x] Dispatch focused frontend accessibility and backend/API-contract review agents over the A4af diff; fix source-backed findings.
- [x] Add the WooCommerce changelog entry.
- [x] Update `analysis-a4af-settings-woopay-residual-parity.md`, `implementation-log.md`, `staging-log.md`, `README.md`, and `spec-conformance-baseline.md` with evidence and residuals. Keep native admin readiness fail-closed.
- [x] Commit source/tests and changelog as separate local commits; do not push.

## Non-Goals

A4af does not complete the full settings parity gate, does not implement the full reference settings hoist, does not port Stripe Billing, does not touch WPCOM, does not modify the standalone WooPayments plugin, and does not implement payment-detail refund/capture/fraud-review money actions.
