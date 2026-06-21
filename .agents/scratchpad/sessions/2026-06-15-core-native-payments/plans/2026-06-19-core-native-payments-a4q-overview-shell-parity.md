---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 08:35
target: A4q native WooPayments Overview shell parity
reconciles:
  - analysis-a4q-overview-shell-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
last_updated: 2026-06-19 08:44
status: superseded
superseded_by: plans/2026-06-19-core-native-payments-a4q-overview-financial-summary-parity.md
---

# A4q Overview Shell Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the native WooPayments Overview shell parity for source-backed notices, tasks, account details, balance, and payout overview content without reintroducing plugin globals or widening disabled-merchant bundles.

**Architecture:** Add a Core-owned Overview projection service and REST route that derives account/status/task/account-details data from native services/options. The frontend consumes that projection through the existing WooPayments admin Overview chunk and renders adapted, accessible components with reference copy and grouping around the existing native deposits APIs.

**Tech Stack:** WooCommerce Core PHP services/DI, WordPress REST API, WooPayments native account/settings/deposits services, React/TypeScript, Jest/RTL, SCSS, Playwriter browser verification, WooCommerce PHP/Javascript lint/build gates.

---

## File Map

- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOverviewService.php`: native Overview projection for account status, account details, account fees, task visibility, account mode, notices, and safe URLs.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`: inject the overview service and expose `GET /wc-admin-settings-payments-woopayments/overview` under existing native runtime/permission gates.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`: route/permission/error coverage.
- Add `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOverviewServiceTest.php`: projection coverage.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts` and `types.ts`: add `getWooPaymentsOverview()` and projection types.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`: load projection and render the Overview shell components.
- Add focused components under `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/`: `overview-notices.tsx`, `overview-task-list.tsx`, `overview-account-details.tsx`, and small shared helpers if needed.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-balances-card.tsx` and `payouts-overview-card.tsx`: reference copy/grouping/notice/footer parity over the existing deposits data.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`: scoped Overview shell/card/task/account details styles in the WooPayments admin chunk only.
- Modify focused tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`: overview data/page/card tests.
- Modify ignored `tools/woopayments-merge/a4-admin-surface-gate.py` and fixture tests to assert the Overview projection and shell source contracts.
- Add changelog `plugins/woocommerce/changelog/fix-native-payments-a4q-overview-parity`.
- Update session `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` after gates.

## Task 1: Backend RED Projection Tests

**Files:**
- Add `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOverviewServiceTest.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`

- [ ] **Step 1: Add projection tests for account status and account details.**

Cover a cached account with `account_id`, `email`, `business_profile.name`, `country`, `status`, `created`, `is_test_drive`, `is_live`, `payments_enabled`, `details_submitted`, `deposits`, `current_deadline`, `has_overdue_requirements`, `requirements.errors`, `fraud_mitigation_settings`, `campaigns`, `account_details`, `fees`, and `capital`. Assert the service returns a sanitized `account_status` shape matching the reference field names, a validated `account_details` shape only when it has `account_status`, `payout_status`, and `banner`, and `account_fees` entries only for enabled payment methods with discounts.

- [ ] **Step 2: Add projection tests for tasks and account mode.**

Cover:

```php
test_overview_projection_exposes_update_business_details_task_when_restricted_soon_with_deadline()
test_overview_projection_exposes_update_business_details_task_when_restricted_and_past_due()
test_overview_projection_exposes_go_live_task_for_connected_test_account()
test_overview_projection_preserves_task_visibility_options()
test_overview_projection_exposes_account_mode_for_test_drive_sandbox_live_and_test_mode()
```

Assert no task appears when the account is rejected or under review, except account details still render.

- [ ] **Step 3: Add REST route tests.**

Extend `WooPaymentsRestControllerTest` so `GET /wc-admin-settings-payments-woopayments/overview` requires `manage_woocommerce`, respects native runtime gating through the existing controller path, returns the overview projection, and converts service exceptions to `woocommerce_rest_woopayments_overview_error`.

- [ ] **Step 4: Run RED PHP tests.**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsOverviewServiceTest|WooPaymentsRestControllerTest'
```

Expected: focused failures because `WooPaymentsOverviewService` and the `/overview` route do not exist yet.

## Task 2: Backend GREEN Projection

**Files:**
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOverviewService.php`
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`

- [ ] **Step 1: Implement `WooPaymentsOverviewService`.**

The service should depend on `WooPaymentsAccountService`, `WooPaymentsSettingsService`, and the native gateway/settings option boundary where needed. Public method:

```php
public function get_overview(): array
```

Return keys:

```php
[
    'account_status' => [...],
    'account_details' => null|array,
    'account_fees' => array,
    'account_loans' => array,
    'overview_tasks_visibility' => [
        'dismissed_todo_tasks' => array,
        'deleted_todo_tasks' => array,
        'remind_me_later_todo_tasks' => array,
    ],
    'tasks' => [
        'show_update_details' => bool,
        'show_go_live' => bool,
        'wpcom_reconnect_url' => null|string,
    ],
    'account_mode' => [
        'connected' => bool,
        'test_drive' => bool,
        'sandbox' => bool,
        'live' => bool,
        'test_mode' => bool,
        'setup_url' => string,
    ],
    'urls' => [
        'settings' => string,
        'payouts' => string,
        'disputes' => string,
    ],
]
```

Use reference field names where the frontend mirrors reference copy. Sanitize scalar fields, preserve structured account-details text from platform after shape validation, and fail closed to empty arrays/nulls when data is absent.

- [ ] **Step 2: Implement task visibility logic.**

Port only source-backed task predicates:

- Update business details: `restricted_soon` with `currentDeadline`, or `restricted` with `pastDue`.
- Go live: connected test-drive/sandbox/non-live account.
- WPCOM reconnect: return `null` unless native platform readiness can source a reconnect URL without plugin runtime coupling. If no native source exists, expose `null` and record the gap as a follow-up instead of faking.

- [ ] **Step 3: Wire REST route.**

Inject optional `?WooPaymentsOverviewService` into `WooPaymentsRestController::init()`, add `get_overview_service()`, register `GET /overview`, and add `protected function get_overview()` with `WP_Error` handling.

- [ ] **Step 4: Run GREEN PHP tests.**

Run the Task 1 PHP command. Expected: focused PHP tests pass.

## Task 3: Frontend RED Overview Tests

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-data.test.ts`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-page.test.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/account-balances-card.test.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/payouts-overview-card.test.tsx`

- [ ] **Step 1: Add overview data test.**

Assert `getWooPaymentsOverview()` calls:

```ts
apiFetch( {
    path: '/wc-admin-settings-payments-woopayments/overview',
    method: 'GET',
} );
```

- [ ] **Step 2: Add page shell RED tests.**

Mock `getWooPaymentsOverview()`, `getWooPaymentsDepositsOverview()`, and `getWooPaymentsRecentDeposits()`. Assert the page renders:

- Query error notice copy for `wcpay-login-error=1`, `wcpay-loan-offer-error=1`, `wcpay-server-link-error=1`, and `wcpay-reset-account-error=1`.
- Account mode notice mount when the projection says the account is test/sandbox.
- Task list entries for update-business-details and go-live, respecting dismissed/deleted/remind-later projection state.
- Account details card with `Account details`, status chip text, `Payouts:`, payout chip text, banner text/link, and `Edit details` when `account_status.accountLink` exists.

- [ ] **Step 3: Add card parity RED tests.**

Extend balance and payout card tests so the native cards render reference copy:

- Balance card labels: `Total`, `Available`, with pending explanation text available through help/details.
- Daily/weekly/monthly payout schedule copy: `Available funds are automatically dispatched ...`.
- Notices: suspended, new-account waiting period, no available funds, negative balance, failed payout, below-minimum payout.
- Footer actions: `View full payout history` and `Change payout schedule` when allowed.

- [ ] **Step 4: Run RED JS tests.**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- client/woopayments/admin/test/overview-data.test.ts client/woopayments/admin/test/overview-page.test.tsx client/woopayments/admin/test/account-balances-card.test.tsx client/woopayments/admin/test/payouts-overview-card.test.tsx --runInBand
```

Expected: focused failures for missing overview data method/components/copy.

## Task 4: Frontend GREEN Overview Shell

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/types.ts`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`
- Add `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-notices.tsx`
- Add `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-task-list.tsx`
- Add `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/overview-account-details.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-balances-card.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/payouts-overview-card.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`

- [ ] **Step 1: Add overview data contract.**

Add projection types and `getWooPaymentsOverview()`. Keep response validation local to component guardrails; do not introduce a second global store for this slice.

- [ ] **Step 2: Add Overview notice stack.**

Render source-backed query notices and account-mode notice from projection data. Use `Notice` with `isDismissible={ false }`, `role="alert"` for errors, and reference copy adapted to WooCommerce text domain. Use native Settings > Payments provider URLs for internal links.

- [ ] **Step 3: Add task list.**

Build a simple accessible list using native buttons/links rather than plugin `CollapsibleList`. Preserve reference task copy and actions for update-business-details and go-live. Hide tasks included in `deleted_todo_tasks`, `dismissed_todo_tasks`, or `remind_me_later_todo_tasks` with a future timestamp. Dismiss/remind/delete actions should persist through the existing settings option REST endpoint if available; if not available, keep controls hidden and record the missing persistence as a follow-up rather than showing broken controls.

- [ ] **Step 4: Add account details card.**

Render account-details card with heading, account status chip, optional `Edit details` external link, optional banner notice, payout status row with accessible details popup when popover exists, account tools links when source-backed URLs exist, and account fee summary when `account_fees` has active discounts. Keep status colors conveyed with text/chip classes, not color alone.

- [ ] **Step 5: Update balance and payouts cards.**

Adjust the cards to reference grouping/copy: Balance shows Total and Available as the primary blocks with accessible details for pending/tooltip copy; Payouts shows schedule text, notices, recent history, and footer actions. Keep existing native deposit API calls and route helpers. Use `ExternalLink` for docs/account links and `Button`/`a` semantics correctly.

- [ ] **Step 6: Run GREEN JS tests.**

Run the Task 3 JS command. Expected: focused JS tests pass.

## Task 5: Gates, Browser Proof, Reviews, And Commit

**Files:**
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py`
- Modify `tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh`
- Add `plugins/woocommerce/changelog/fix-native-payments-a4q-overview-parity`
- Update session logs after verification.

- [ ] **Step 1: Widen ignored A4 admin-surface gate.**

Add static assertions that the native Overview route has the overview projection fetch, source-backed account details/task components, reference notice copy, and scoped CSS. Run:

```bash
python3 -m py_compile tools/woopayments-merge/a4-admin-surface-gate.py
bash tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh
python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo "$PWD" --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4q-admin-surface-gate.json
```

- [ ] **Step 2: Run focused static/test gates.**

Run focused PHPUnit/Jest from earlier tasks, targeted ESLint/Stylelint, admin-library `ts:check`, admin build, changed-file PHPCS, PHPStan for touched production PHP, changelog validation, `git diff --check -- . ':!.agents'`, and branch lint.

- [ ] **Step 3: Run Playwriter Overview proof.**

Use target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview` and reference `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Foverview`. Verify account notices/task/account-details/balance/payout sections, layout width, and no console errors except known local admin `unload` permissions-policy noise. Capture screenshots under the session `data/` folder and restore any seeded options.

- [ ] **Step 4: Run review agents.**

Use at minimum an a11y review for task/account-detail interactions, a WP architecture review for projection/route boundaries, and an API/security review for sanitized account payload and route permissions. Fix source-backed findings and rerun focused gates.

- [ ] **Step 5: Add changelog and commit.**

Commit source/tests/styles as one logical change and the changelog separately. Do not stage `.agents` or ignored harness files unless explicitly requested. Do not push.

## Remaining After A4q

A4q does not complete N12. Remaining known reopened-A4 work: embedded notification/dispute-readiness/inbox/loan Overview extras if not folded in, Payouts/Transactions/Disputes list/detail parity, Card Readers/Capital visual framing, Reports/Documents native UI/API disposition, broader copy/content parity, and the widened final A4 exit gate. Native admin readiness remains fail-closed.
