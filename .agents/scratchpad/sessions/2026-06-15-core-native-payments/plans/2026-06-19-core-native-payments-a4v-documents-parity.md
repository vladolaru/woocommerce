---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 14:18 EEST
target: A4v native WooPayments Documents parity
reconciles:
  - ../analysis-a4v-admin-surface-selection.md
  - ../analysis-a4g-remaining-admin-surfaces.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
  - ../staging-log.md
  - ../../2026-06-19-woopayments-documents-gap-map/analysis.md
status: draft
---

# A4v Documents Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the native WooPayments Documents admin surface under Core Settings > Payments, including its VAT-gated download workflow, without mixing in the larger Reports area.

**Architecture:** Add Documents as a WooPayments-specific provider subroute at `/woopayments/documents`, with plugin-era `/payments/documents` as redirect-only compatibility. Keep UI code in a dedicated lazy WooPayments admin chunk; use DataViews for the Documents table because the surface is a queryable list and DataViews is already the native table primitive for WooPayments admin lists. Gate menu, REST, and page behavior from cached account document eligibility, and preserve the reference list/download/VAT hooks so existing integrations keep working.

**Tech Stack:** WooCommerce Core PHP services/controllers, WordPress REST API, native WooPayments API client, `@wordpress/dataviews/wp`, WordPress components, Jest/React Testing Library, PHPUnit, Playwriter, WooPayments merge harness.

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php`: expose source-backed helpers for document eligibility and VAT submission state.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`: add a safe `documents` account-summary projection with `enabled`, `has_submitted_vat_data`, and `country`.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/api.ts` and `plugins/woocommerce/client/admin/client/woopayments/settings/types.ts`: carry the new account-summary shape to native admin surfaces.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`: add `Documents` menu item and `/payments/documents` legacy redirect only when the native account says Documents are enabled.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php` and `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`: prove the projection and navigation gates.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: add `documents` and `vat` API helpers.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDocumentsListRequest.php`: preserve `WCPay\Core\Server\Request\List_Documents` and `wcpay_list_documents_request`.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDocumentsRestController.php`: register `/wc/v3/payments/documents`, `/summary`, `/{document_id}`, `/wc/v3/payments/vat/{vat_number}`, and `/wc/v3/payments/vat`.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the Documents REST controller.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDocumentsRestControllerTest.php`: focused REST/request/download/VAT tests.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`: API-client route/filter tests.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/documents/` files for `index.tsx`, `page.tsx`, `data.ts`, `query.ts`, `types.ts`, `vat-modal.tsx`, and `style.scss`.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`: add lazy `/woopayments/documents` route in its own chunk.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` only to import the Documents scoped stylesheet or shared layout styles.
- Add tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`: `documents-data.test.ts`, `documents-page.test.tsx`, and route coverage in `routes.test.tsx`.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py` only to add honest Documents route/menu/chunk/API checks; do not mask WP notices or force green when Documents is disabled in account data.
- Add one WooCommerce changelog entry.

## Subagent Strategy

- Worker 1 can own the PHP account/navigation projection and tests: `WooPaymentsAccountService.php`, `WooPaymentsService.php`, `WooPaymentsAdminNavigationController.php`, and their PHP tests.
- Worker 2 can own the PHP Documents/VAT REST/API layer and tests: `WooPaymentsApiClient.php`, `WooPaymentsDocumentsListRequest.php`, `WooPaymentsDocumentsRestController.php`, `class-woocommerce.php`, and related PHPUnit tests.
- Main agent should own the frontend Documents DataViews/VAT UI initially because it touches route, table behavior, modal accessibility, and browser verification together. After implementation is green, use review subagents for API contract, WP architecture/reliability, accessibility, frontend code quality, and final code review.
- Workers are not alone in the codebase: they must not revert unrelated changes, must keep write sets scoped, and must report exact files changed.

## Task 1: Account, Navigation, and Route Eligibility

- [ ] **Write RED account projection tests.** Extend `WooPaymentsServiceTest::test_get_account_summary_returns_safe_native_account_state()` to seed `is_documents_enabled`, `has_submitted_vat_data`, and `country`, then assert `summary['documents']` exposes `enabled`, `has_submitted_vat_data`, and `country` without leaking raw account payloads. Add a no-account/default test proving all fields fail closed.
- [ ] **Write RED account-service helper tests.** Extend `WooPaymentsAccountServiceTest` with helpers for `is_documents_enabled()` and `has_submitted_vat_data()` against true, false, missing, and no-account cached data.
- [ ] **Write RED navigation tests.** Replace the broad “Reports and Documents absent” assertion with tests that Reports remains absent, Documents appears only when document eligibility is true, the menu URL points at `/woopayments/documents`, and `/payments/documents` legacy WC Admin links redirect only when the native gateway and Documents eligibility are both true. Include deep-link query preservation for `document_id` and `document_type`.
- [ ] **Run focused RED tests.** Run `pnpm test:php:env -- --filter 'WooPaymentsServiceTest|WooPaymentsAccountServiceTest|WooPaymentsAdminNavigationControllerTest' --stop-on-failure`. Expected: failures for missing helpers/projection/navigation.
- [ ] **Implement account and navigation eligibility.** Add the two account helpers, the safe `documents` projection to account summary, `PATH_DOCUMENTS`, gated `Documents` menu item, and `/payments/documents` redirect mapping guarded by document eligibility. Keep Reports absent.
- [ ] **Update frontend account types.** Add a `documents` field to `WooPaymentsAccountResponse` and keep it optional-tolerant in TypeScript consumers.
- [ ] **Run focused GREEN tests.** Re-run the focused PHP tests and run targeted TypeScript/Jest only if the account type change requires it before moving to REST work.

## Task 2: Documents and VAT REST/API Contract

- [ ] **Write RED API-client tests.** In `WooPaymentsApiClientTest`, assert `get_documents()` calls platform API `documents` through a `WCPay\Core\Server\Request\List_Documents` alias and `wcpay_list_documents_request`; `get_documents_summary()` calls `documents/summary` with filter-only params; `get_document()` validates `^[\\w-]+$` and requests a raw response; `validate_vat()` uses `vat/{vat_number}` and `wcpay_validate_vat_request`; and `save_vat_details()` posts to `vat` with optional `vat_number`, `name`, and `address`.
- [ ] **Write RED REST tests.** Create `WooPaymentsDocumentsRestControllerTest` asserting route registration only when native owns runtime and Documents are enabled, `manage_woocommerce` permission, list query allowlist, summary filter-only behavior, invalid document ID failure, raw download response headers/body/status, `wcpay_document_downloaded` Tracks event, VAT validation route hook, VAT save required args, and API exception conversion/logging.
- [ ] **Run RED tests.** Run `pnpm test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsDocumentsRestControllerTest' --stop-on-failure`. Expected: failures for missing methods/classes/routes.
- [ ] **Implement list request and aliases.** Add `WooPaymentsDocumentsListRequest` using the existing paginated request base, `get_api(): documents`, legacy alias `WCPay\Core\Server\Request\List_Documents`, list filters `match`, date filters, `type_is`, `type_is_not`, and `from_params()` for direct API-client use.
- [ ] **Implement API helpers.** Add `DOCUMENTS_API` and `VAT_API` constants, `get_documents()`, `get_documents_summary()`, `get_document()`, `validate_vat()`, and `save_vat_details()`. Use existing request/filter helpers where possible and validate route IDs before transport.
- [ ] **Implement REST controller.** Register list, summary, download, VAT validate, and VAT save routes behind runtime ownership and document eligibility. Preserve raw download semantics as much as Core REST allows: upstream status, `Content-Type`, `Content-Disposition`, body passthrough, and no JSON wrapping for the file response. Record the `wcpay_document_downloaded` event after a successful provider response.
- [ ] **Register bootstrap.** Add the controller registration next to the other native WooPayments REST controllers in `class-woocommerce.php`.
- [ ] **Run focused backend GREEN tests and static checks.** Run the focused PHPUnit command, PHP syntax on changed PHP files, PHPCS for changed production/test PHP, and PHPStan for changed production PHP. Record any isolated test-file PHPStan bootstrap limitations honestly.

## Task 3: Native Documents DataViews UI and VAT Modal

- [ ] **Write RED data/query tests.** Add tests for document query defaults (`page=1`, `pagesize=25`, `sort=date`, `direction=desc`), filter serialization (`match`, `date_before`, `date_after`, `date_between[]`, `type_is`, `type_is_not`), summary filter-only requests, document download URL creation with REST nonce/admin URL behavior, VAT validate/save requests, and account-summary document metadata fetching.
- [ ] **Write RED route tests.** Update `routes.test.tsx` to expect `/woopayments/documents` with a dedicated `settings-payments-woopayments-documents` chunk and no `/payments/documents` provider route.
- [ ] **Write RED page tests.** Add tests for: enabled Documents page renders heading, Test Mode notice when account test mode is true, count summary, DataViews fields Date/Type/Description/Download, `Tax Invoice` display, period description, loading/error/empty states, Download accessible button, direct deep-link behavior requiring both `document_id` and `document_type`, immediate download when VAT data is submitted, VAT modal interruption when missing, VAT save flips local state and retries the interrupted download, and SpotlightPromotion mount.
- [ ] **Run RED frontend tests.** Run `pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/woopayments/admin/test/routes.test.tsx client/woopayments/admin/test/documents-data.test.ts client/woopayments/admin/test/documents-page.test.tsx --runInBand`. Expected: failures for missing route/data/page.
- [ ] **Implement frontend data/query helpers.** Build small helpers under `admin/documents` with no legacy `wcpaySettings` dependency. Fetch account metadata through `/wc-admin/settings/payments/woopayments/account`, list/summary through `/wc/v3/payments/documents`, VAT through `/wc/v3/payments/vat`, and document URLs through the REST URL/nonce mechanism used elsewhere in Core admin.
- [ ] **Implement DataViews list.** Use `@wordpress/dataviews/wp` directly or the existing thin wrapper if it stays suitable. Preserve server-owned pagination/sort/search/filter state and accessible loading/empty states. Do not force DataViews to match reference `TableCard` styling exactly; add scoped styling only for layout, density, focus, notices, summary, and modal spacing.
- [ ] **Implement VAT modal.** Use WordPress `Modal`, `CheckboxControl`, `TextControl`, `TextareaControl`, `Notice`, and `Button` to preserve the reference two-step behavior and copy, but avoid importing the plugin wizard abstraction. Keep focus managed by the modal component, ensure errors use `Notice`, and announce busy/error states.
- [ ] **Implement route/page gate.** The provider route registry remains static; the page must fail closed when account summary says Documents are disabled or unavailable, with no list fetch. Menu and REST remain account-gated from PHP.
- [ ] **Run focused frontend GREEN tests and type/style checks.** Re-run focused Jest, targeted ESLint for changed TS/TSX files, Stylelint for changed SCSS, and `pnpm --filter=@woocommerce/admin-library ts:check`.

## Task 4: Harness, Browser, Reviews, Logs, and Commit

- [ ] **Update A4 admin surface gate.** Add checks for route registration, lazy chunk name, no plugin-era `/payments/documents` provider route, menu/redirect contract, REST route availability when account data enables Documents, and absence when disabled. Preserve progress output and do not suppress WP notices/warnings.
- [ ] **Run focused backend/frontend gates.** Run all focused PHPUnit/Jest commands from Tasks 1-3, changed-file PHP lint, PHPCS, PHPStan on production PHP, targeted ESLint/Stylelint, TypeScript, admin build for affected chunks, `git diff --check`, and changelog validation.
- [ ] **Run harness and browser proof.** Run the restored harness gate set relevant to A4 admin surfaces, then use Playwriter against target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdocuments` and reference `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fdocuments` when account data enables Documents. Compare reachability, table controls, VAT modal, download URL behavior, console/network failures, and visible WP/PHP notices. If local account data lacks Documents, seed only local target/reference account cache through documented local tools and record the exact seed; do not fake platform responses to hide product bugs.
- [ ] **Dispatch review subagents.** Run API contract, WP architecture/reliability, accessibility, frontend code-quality, and final code review agents over the integrated diff. Fix source-backed findings before closing the slice.
- [ ] **Update session docs.** Update `analysis-a4v-admin-surface-selection.md`, `implementation-log.md`, `staging-log.md`, and, if the baseline contract changes, `spec-conformance-baseline.md`. Keep scratchpad prose un-hard-wrapped and do not lint `.agents`.
- [ ] **Add changelog and commit.** Add one WooCommerce changelog entry, stage only the A4v logical diff, commit locally with a Conventional Commit message, and do not push.

## Exit Criteria

- Native WooPayments Documents is merchant-reachable as `/woopayments/documents` under Core Settings > Payments when and only when the account is document-enabled.
- Legacy `/payments/documents` links redirect to the native route with scalar query args preserved; no plugin-era route is registered as a native provider route.
- `/wc/v3/payments/documents`, `/summary`, `/{document_id}`, `/payments/vat/{vat_number}`, and `/payments/vat` preserve reference query names, hooks, permissions, download behavior, Tracks, and fail-closed eligibility.
- The frontend renders a complete Documents list with DataViews, filters/query state, summary, test-mode notice, spotlight, direct deep-link download, and VAT-interrupted download parity.
- WooPayments Documents JS/CSS is bundled in a separate WooPayments admin chunk and does not inflate the generic Settings Payments entry.
- Reports remains unported and hidden until its own feature-gated A4 slice.
- Native admin readiness remains fail-closed; A5 cutover readiness is not advanced by this slice alone.
