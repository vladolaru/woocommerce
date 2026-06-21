---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 21:10
target: A4aw VAT modal deep-link parity
reconciles:
  - analysis-a4aw-vat-modal-deep-link-parity.md
  - review-a4au-settings-inventory.md
last_updated: 2026-06-20 21:54
status: final
---

# A4aw VAT Modal Deep-Link Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments parity for VAT tax-details deep links by opening the existing Core-owned VAT modal from the provider settings route and preserving the plugin-era frontend redirect entry point.

**Architecture:** Keep the modal and VAT API ownership in the existing Documents/VAT module, and add only a narrow settings-page orchestrator for deep-link state. Keep legacy URL compatibility in `WooPaymentsAdminNavigationController`, which already owns native WooPayments admin route compatibility.

**Tech Stack:** React/TypeScript, WordPress data/notices, WooCommerce admin Jest/RTL, PHP/WP hooks, PHPUnit.

---

## File Structure

- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` to detect `woopayments-vat-details-modal=true`, fetch account summary only when needed, open `WooPaymentsVatModal`, show reference notices, and clear the query arg on close/completion.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx` for RED/GREEN coverage of the settings deep-link states.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php` to hook and build the temporary frontend VAT redirect URL.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php` for RED/GREEN coverage of hook registration and redirect URL construction.
- Add a WooCommerce changelog entry under `plugins/woocommerce/changelog/`.
- Update scratchpad `implementation-log.md`, `staging-log.md`, and this plan as each phase completes.

## Task 1: RED Tests

- [x] Add Jest tests in `settings-page.test.tsx` that set `window.history.replaceState( null, '', '/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings&woopayments-vat-details-modal=true' )`, mock `/wc-admin/settings/payments/woopayments/account`, and assert the `Set your tax details` dialog opens only when `documents.enabled=true` and `documents.has_submitted_vat_data=false`.
- [x] Add Jest tests asserting documents-disabled creates `Tax details collection is not available for your account.` and already-submitted creates `Tax details are already submitted.` without opening the dialog.
- [x] Add Jest coverage that clicking the modal Cancel button removes `woopayments-vat-details-modal` while preserving unrelated query args and the provider `path`.
- [x] Add Jest coverage for modal completion by selecting the no-tax-ID path, entering business name/address, mocking `/wc/v3/payments/vat`, clicking Confirm, and asserting `Tax details updated` plus query cleanup.
- [x] Add Jest coverage that rendering settings without the VAT query does not call `/wc-admin/settings/payments/woopayments/account` solely for VAT handling.
- [x] Add PHPUnit tests in `WooPaymentsAdminNavigationControllerTest.php` asserting `register()` attaches `redirect_vat_details_request` to `template_redirect` when native owns payments, does not attach it when native does not own payments, and that `get_vat_details_redirect_url( array( 'woopayments-vat-details-redirect' => '1' ) )` returns the native settings route with `woopayments-vat-details-modal=true`.
- [x] Run RED commands and record expected failures: `pnpm test:js -- settings-page.test.tsx --runInBand` from `plugins/woocommerce/client/admin`, and `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsAdminNavigationControllerTest` from repo root.

## Task 2: Frontend Implementation

- [x] Import `useRef`, `getWooPaymentsAccountSettings`, `WooPaymentsVatModal`, `WooPaymentsVatDetails`, and WordPress notices dispatch into `settings-page.tsx`.
- [x] Add a small `getVatModalSearchParams()` or inline `URLSearchParams` helper that reads `window.location.search` safely and only treats exact `true` as active.
- [x] Add local state for VAT modal open, VAT account state, and a one-shot attempted flag so the query is handled once per page load unless the component remounts.
- [x] In an effect, do nothing without `woopayments-vat-details-modal=true`; when present, fetch account summary, then show the reference error/info notice or open the modal. On account fetch failure, show the same unavailable notice rather than silently failing.
- [x] Add a close handler that closes the modal and calls `window.history.replaceState()` with only the VAT query arg removed, preserving `page`, `tab`, `path`, and unrelated query params.
- [x] Add a completion handler that updates the local VAT state to submitted, creates the `Tax details updated` notice, and delegates to the close handler.
- [x] Render `WooPaymentsVatModal` only through a lazy optional `settings-payments-woopayments-vat-modal` chunk so normal settings visits do not load the VAT modal code or styles.

## Task 3: PHP Redirect Implementation

- [x] Add constants for the VAT redirect and modal query keys in `WooPaymentsAdminNavigationController.php`.
- [x] Register `template_redirect` with `redirect_vat_details_request` when native should register, and remove it in tests where needed.
- [x] Add `get_vat_details_redirect_url( array $request ): string` that returns empty unless the redirect query exists and otherwise returns `Utils::wc_payments_settings_url( self::PATH_SETTINGS, array( self::VAT_MODAL_QUERY_ARG => 'true' ) )`.
- [x] Add `redirect_vat_details_request(): void` that skips REST and AJAX requests, calls the helper with `$_GET`, and redirects/exits only when the helper returns a non-empty URL.

## Task 4: GREEN Gates

- [x] Run focused Jest until green: `cd plugins/woocommerce/client/admin && pnpm test:js -- settings-page.test.tsx --runInBand`.
- [x] Run focused PHPUnit until green: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsAdminNavigationControllerTest`.
- [x] Run exact-file ESLint for touched TS/TSX tests/source and PHP syntax for touched PHP.
- [x] Run admin TypeScript lint and admin bundle build because the settings page imports the modal across module boundaries.

## Task 5: Browser, Logs, Review, Commit

- [x] Use Playwriter on the target store to load `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings&woopayments-vat-details-modal=true`, verify the settings page and VAT dialog behavior when the account state permits it, and capture JSON/screenshot evidence under `data/a4aw-vat-modal-deep-link/`.
- [x] Scan target `debug.log` and recent target Docker logs for PHP/WP notices, warnings, deprecations, fatals, database errors, stack traces, and actual 5xx markers.
- [x] Dispatch focused review agents after implementation: code/reliability for PHP redirect and a11y/JS-test for modal behavior.
- [x] Close review findings: account-fetch failure branch coverage, shared VAT modal focus-retention coverage with mutation proof, and direct PHP redirect/AJAX skip coverage.
- [x] Add changelog, run changelog validation, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch`.
- [x] Update `implementation-log.md`, `staging-log.md`, `README.md`, and this plan to final status, then commit source/tests and changelog locally if all gates pass. Do not push.

## Deferred Checkpoints

- [ ] After H31 is cleanly closed, check the N8 supervisor advisory in `supervisor-prompt-2026-06-17-1311.md`.
- [ ] Once A5c is fully done, reopen A4 for feature parity according to `supervisor-prompt-2026-06-18-2344-N12.md`.
