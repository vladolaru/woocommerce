---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 13:04
target: exp/core-native-payments — A4u authorizations and uncaptured transaction parity
reconciles:
  - analysis-a4u-authorizations-uncaptured-parity.md
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4u Authorizations Uncaptured Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments Uncaptured authorization list/detail/action parity on the Transactions admin surface without mixing in Reports/Documents or full detail polish.

**Architecture:** Add a provider-owned native authorizations REST controller and API-client list/detail helpers behind the runtime arbiter. Preserve reference route paths for admin compatibility, but wire capture/cancel through native payment/order abstractions instead of reviving standalone plugin ownership. Reuse the A4s DataViews list infrastructure for the Uncaptured tab because this is a queryable list surface with row actions and durable presentation preferences.

**Tech Stack:** WooCommerce Core PHP services/controllers, WordPress REST API, native WooPayments API client, Jest/React Testing Library, `@wordpress/dataviews/wp`, WooCommerce admin React/SCSS.

---

### Task 1: Native Authorizations REST Contract

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAuthorizationsRestController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAuthorizationsRestControllerTest.php`

- [ ] **Step 1: Write RED API-client tests**

Add tests asserting `get_authorizations( $params )` calls the preserved `authorizations` platform path with the reference-compatible `wcpay_list_authorizations_request` hook, `get_authorization( $payment_intent_id )` lists with `charge_id_is`/single-row semantics when that is what the reference contract requires, and `get_authorizations_summary( $params )` keeps the already-preserved summary hook behavior.

- [ ] **Step 2: Run focused API-client tests and verify RED**

Run: `pnpm test:php:env -- --filter WooPaymentsApiClientTest --stop-on-failure`

Expected: the new list/detail tests fail because the native client exposes only the summary helper.

- [ ] **Step 3: Implement API-client list/detail helpers**

Add the narrow list/detail helpers to `WooPaymentsApiClient` using the same request-filter helper style as the A4s transaction/dispute/deposit APIs. Keep query params explicit so authorizations do not inherit transaction-only filters accidentally.

- [ ] **Step 4: Write RED REST controller tests**

Add controller tests for `GET /wc/v3/payments/authorizations`, `GET /wc/v3/payments/authorizations/summary`, and `GET /wc/v3/payments/authorizations/{id}`. Assert runtime-owner gating, `manage_woocommerce` permission checks, filtered query mapping, list response enrichment/normalization, empty detail response, API-exception conversion, and route registration.

- [ ] **Step 5: Implement and register the REST controller**

Create `WooPaymentsAuthorizationsRestController` with readable routes under `wc/v3`, a bounded authorization query param allowlist, reference-compatible date/customer/order/source filters where source-backed, and response normalization matching the reference authorization shape. Register the controller from WooCommerce bootstrap with the other native WooPayments REST controllers.

- [ ] **Step 6: Run focused backend tests and static checks**

Run: `pnpm test:php:env -- --filter 'WooPaymentsAuthorizationsRestControllerTest|WooPaymentsApiClientTest'`

Then run PHP syntax, PHPCS changed-file lint, and PHPStan for touched production PHP once the focused tests are green.

### Task 2: Capture And Cancel Admin Mutations

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAuthorizationsRestController.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementOrderService.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAuthorizationsRestControllerTest.php`
- Modify as needed: existing native payment-processing/provider tests that already cover capture/cancel behavior.

- [ ] **Step 1: Write RED action-route tests**

Add tests for `POST /wc/v3/payments/orders/{order_id}/capture_authorization` and `POST /wc/v3/payments/orders/{order_id}/cancel_authorization`. Assert missing order, intent/order mismatch, refunded/uncapturable order, provider failure, success response shape, order-note/meta side effects already owned by the native provider layer, and route permission checks.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `pnpm test:php:env -- --filter WooPaymentsAuthorizationsRestControllerTest --stop-on-failure`

Expected: failures for unregistered action routes or missing action handling.

- [ ] **Step 3: Implement native action boundary**

Route action requests through existing native provider/payment-processing services or the smallest provider-owned wrapper around them. Preserve reference route names and request payload keys, map domain failures to the reference-style error codes/messages, log unexpected provider errors locally, and never return success when the order/payment-intent relationship cannot be verified.

- [ ] **Step 4: Run focused action tests and broader capture/cancel tests**

Run: `pnpm test:php:env -- --filter 'WooPaymentsAuthorizationsRestControllerTest|PaymentProcessingServiceTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsMoneyMovementOrderServiceTest'`

If one of those test classes does not exist locally, record the actual focused substitute in the implementation log and run the closest source-backed native capture/cancel suite.

### Task 3: Uncaptured Transactions Tab And Actions

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/query.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transactions-page.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/authorizations-page.tsx` or keep inside `transactions-page.tsx` if the local file stays clearer.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dataviews.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-data.test.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-query.test.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-dataviews.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`

- [ ] **Step 1: Write RED frontend data/query tests**

Add tests asserting authorizations list/detail/summary requests hit `/wc/v3/payments/authorizations`, `/summary`, and `/{id}`, action requests hit `/wc/v3/payments/orders/{order_id}/capture_authorization` and `/cancel_authorization`, `capture_by` sorting serializes as `created`, and authorization-only filters do not leak transaction/dispute-only params.

- [ ] **Step 2: Write RED UI tests**

Add React tests asserting the Transactions route exposes Transactions and Uncaptured tabs, the Uncaptured tab renders DataViews headers for authorized date, capture by, order, risk, amount, customer, and actions, row capture/cancel buttons have accessible names and pending state, success/error notices are dispatched, and the tab preserves A4s query/presentation behavior.

- [ ] **Step 3: Run focused frontend tests and verify RED**

Run: `pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/woopayments/admin/test/money-movement-data.test.ts client/woopayments/admin/test/money-movement-query.test.ts client/woopayments/admin/test/money-movement-dataviews.test.tsx client/woopayments/admin/test/money-movement-pages.test.tsx --runInBand`

Expected: failures for missing authorizations data functions, tab, columns, and action buttons.

- [ ] **Step 4: Implement the frontend data and Uncaptured UI**

Add typed authorization data functions, a tab selector in the existing Transactions page, a DataViews-backed authorization table, row action handlers with disabled/pending states, live region/notice handling, and durable view preferences keyed separately from Transactions/Disputes/Payouts. Keep the money-movement chunk shared with the existing transactions/disputes code; do not create a broad always-loaded admin bundle.

- [ ] **Step 5: Run focused frontend tests and type/lint checks**

Run the focused Jest command again, then targeted ESLint for touched TS/TSX files, targeted Stylelint for touched SCSS, and `pnpm --filter=@woocommerce/admin-library ts:check`.

### Task 4: Gate, Browser Proof, Reviews, Logs, Commit

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4u-authorizations-uncaptured-parity.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Add: WooCommerce plugin changelog entry.

- [ ] **Step 1: Run focused reviews**

Dispatch focused subagent reviews for API contract, WP architecture/reliability, accessibility, and frontend code quality after the implementation is green. Fix source-backed findings before proceeding.

- [ ] **Step 2: Run full relevant gates**

Run focused backend PHPUnit, focused frontend Jest, changed-file PHPCS, PHPStan for touched production PHP, targeted ESLint/Stylelint, `ts:check`, admin bundle build, changelog validation, `git diff --check -- . ':!.agents'`, and branch lint. Run the ignored A4 admin-surface gate if it has or receives authorizations coverage.

- [ ] **Step 3: Browser-verify target and reference**

Use Playwriter against target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions` and reference `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Ftransactions`. Verify Transactions/Uncaptured tab reachability, action affordances when data is present or the honest empty state when no uncaptured rows exist, no failed native REST responses, no PHP notices/warnings, and no plugin-era asset ownership on target.

- [ ] **Step 4: Update durable session logs and commit**

Record the slice result, review findings, gates, browser evidence, caveats, and remaining A4 blockers in `implementation-log.md` and `staging-log.md`. Mark this plan and analysis implemented only after verification. Add a WooCommerce changelog entry and commit product changes using one logical source commit plus a separate changelog commit if needed.
