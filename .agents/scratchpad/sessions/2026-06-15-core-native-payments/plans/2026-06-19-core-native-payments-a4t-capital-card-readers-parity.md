---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 12:16
target: exp/core-native-payments — A4t Capital and Card Readers parity
reconciles:
  - analysis-a4t-capital-card-readers-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: implemented
---

# A4t Capital Card Readers Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the already-native Capital and Card Readers admin parity gaps without claiming the larger Reports/Documents or Uncaptured authorizations slices.

**Architecture:** Keep these surfaces as native Settings > Payments provider subroutes with their existing split bundles. Add the missing provider-owned Capital link backend path, then improve the two frontend pages using accessible semantic markup and reference-backed contracts. Do not introduce DataViews here because the reference surfaces are static settings/list cards rather than configurable data grids.

**Tech Stack:** WooCommerce Core PHP services/controllers, WordPress REST, Jest/React Testing Library, native admin React/SCSS, `@wordpress/components`.

---

### Task 1: Capital Loan Offer Redirect Contract

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestControllerTest.php`

- [x] **Step 1: Write RED API-client tests**

Add tests asserting `create_capital_link( $return_url, $refresh_url )` posts to `/sites/{blog}/wcpay/accounts/capital_links?test_mode=1`, uses payload keys `type=capital_financing_offer`, `return_url`, and `refresh_url`, uses the user token, and preserves the legacy `wcpay_get_account_capital_link` filter/request object.

- [x] **Step 2: Run focused API-client tests and verify RED**

Run: `pnpm test:php:env -- --filter WooPaymentsApiClientTest`

Expected: failures for the missing `create_capital_link()` method or missing endpoint/filter behavior.

- [x] **Step 3: Implement the API-client method**

Add a method that calls the preserved legacy request-filter path against `accounts/capital_links`, POST, hook `wcpay_get_account_capital_link`, and payload `{ type: 'capital_financing_offer', return_url, refresh_url }`.

- [x] **Step 4: Write RED REST/redirect tests**

Add controller tests for a readable/creatable native route that handles `wcpay-loan-offer`, calls the API-client link method, redirects to the returned `url`, denies non-`manage_woocommerce` users, and redirects to the native overview route with `wcpay-loan-offer-error=1` when the API fails or omits `url`.

- [x] **Step 5: Implement route/handler**

Register the route only when native owns runtime. Use existing capability checks. Build return and refresh URLs with the native Settings > Payments provider route helpers and the existing overview error query. Keep failure loud to the merchant via the existing overview notice, not silent fall-through.

- [x] **Step 6: Run focused PHP tests and static checks**

Run the focused Capital controller/API tests, then direct `php -l`/PHPCS/PHPStan on changed PHP files if the focused tests pass.

### Task 2: Capital Page Parity

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/types.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/style.scss`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/capital-page.test.tsx`

- [x] **Step 1: Write RED frontend tests**

Add tests for the active-loan `View transactions` CTA, summary text including period due date/minimum wording, explicit row action links, active/paid-off status chips, and loan summary metrics: loan count, same-currency total, and same-currency fixed fees.

- [x] **Step 2: Run focused Jest tests and verify RED**

Run: `pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/woopayments/admin/test/capital-page.test.tsx`

Expected: failures for missing CTA, summary, chips, and all-cell links.

- [x] **Step 3: Implement Capital parity markup**

Keep the page in the `settings-payments-woopayments-capital` bundle. Preserve loading/error/empty live regions. Add the active-loan CTA when a loan id is available from loans, add one explicit row action to `/woopayments/transactions?loan_id_is=...`, add chip classes for active/paid-off states, and render same-currency summary totals before the table.

- [x] **Step 4: Improve Capital SCSS**

Style the active loan overview and loan list as clean admin cards with stable grid/table dimensions, accessible focus styles, non-overlapping content, and no decorative one-hue theme. Do not nest UI cards inside cards.

- [x] **Step 5: Run focused Jest tests and type/lint checks**

Run the Capital page tests, then targeted TypeScript/ESLint for changed TSX files.

### Task 3: Card Readers Page Parity

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/card-readers/style.scss`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/card-readers-page.test.tsx`

- [x] **Step 1: Write RED frontend tests**

Add tests for the combined reference explanatory copy, settings-style connected readers wrapper, active/inactive status chip classes, preserved loading/empty/error announcements, and no extra DataViews controls.

- [x] **Step 2: Run focused Jest tests and verify RED**

Run: `pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/woopayments/admin/test/card-readers-page.test.tsx`

Expected: failures for wrapper/copy/chip structure.

- [x] **Step 3: Implement Card Readers visual parity**

Keep the static list as semantic markup, not DataViews. Use a heading/description section, a list/table-like card wrapper, and clear status badges while preserving accessible announcements.

- [x] **Step 4: Run focused Jest tests and type/lint checks**

Run the Card Readers page tests, then targeted TypeScript/ESLint for changed TSX files.

### Task 4: Gate, Browser Check, Logs, Commit

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Add: WooCommerce changelog entry for the plugin package

- [x] **Step 1: Run focused automated gates**

Run focused PHP tests, focused admin Jest tests, admin TypeScript check, targeted lint, PHPStan for changed PHP production files, changelog validation, `git diff --check -- . ':!.agents'`, and branch lint excluding scratchpad as established.

- [x] **Step 2: Run Playwriter browser checks**

Verify native target Capital and Card Readers pages load through Settings > Payments provider routes, visible copy/status/table layout is usable, Capital transaction links preserve the native route, and no visible WP notices/warnings appear.

- [x] **Step 3: Update logs**

Record source-backed scope, DataViews decision, tests, browser evidence, remaining A4 caveats, and explicit non-claims for Reports/Documents and Uncaptured authorizations.

- [x] **Step 4: Commit**

Commit one logical WooCommerce Core change plus changelog if gates pass. Do not push.
