---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 14:24
last_updated: 2026-06-16 14:38
status: final
---

# B3u Deprecated WooPayments Onboarding Surface Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the deprecated WooPayments-specific onboarding task surface from WooCommerce Core while preserving safe redirects for the legacy `task=woocommerce-payments` and `action=setup-woocommerce-payments` entry points.

**Architecture:** Treat the deprecated WooPayments onboarding flow as one surface spanning the PHP task class, the task-list fill, the task header, and the marketing note that still points into the old install/setup path. Delete the deprecated task/note creation surfaces, keep the old task/action URLs as compatibility redirects into the current Payments settings flow, and remove the obsolete `wcpayWelcomePageIncentive` setting that only exists for the deleted task header.

**Tech Stack:** WooCommerce Core PHP, WC Admin React/Jest, PHPUnit, source-boundary regression tests, PageController redirect coverage.

---

### Task 1: Lock the Deprecated Surface With RED Tests

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Admin/PageControllerTest.php`
- Create: `plugins/woocommerce/client/admin/client/task-lists/setup-task-list/components/task-headers/index.test.ts`

- [x] **Step 1: Extend the PHP boundary test for deprecated onboarding removal**

Add a new test that asserts these files no longer exist:

```php
'src/Admin/Features/OnboardingTasks/Tasks/WooCommercePayments.php',
'src/Internal/Admin/Notes/WooCommercePayments.php',
'client/admin/client/task-lists/fills/woocommerce-payments.tsx',
'client/admin/client/task-lists/setup-task-list/components/task-headers/woocommerce-payments.js',
```

Also scan production PHP sources for:

```php
'WooCommercePayments::class',
'new WooCommercePayments()',
```

and scan production client/task-list sources for:

```text
'woocommerce-admin-task-wcpay-page'
'wcpayWelcomePageIncentive'
```

The assertions should ignore test files and only target production sources.

- [x] **Step 2: Add the legacy action redirect RED test**

In `PageControllerTest.php`, add a test for:

```php
$_GET['page']   = 'wc-admin';
$_GET['action'] = 'setup-woocommerce-payments';
```

Require a redirect to:

```php
admin_url( 'admin.php?page=wc-settings&tab=checkout&from=WCADMIN_PAYMENT_TASK' )
```

Expected RED: no redirect exists yet for the legacy action.

- [x] **Step 3: Add a task-header RED test**

Create `components/task-headers/index.test.ts` that imports `taskHeaders` and asserts:

```ts
expect( taskHeaders ).not.toHaveProperty( 'woocommerce-payments' );
expect( taskHeaders ).toHaveProperty( 'payments' );
```

Expected RED: the deprecated WooPayments task header is still registered.

- [x] **Step 4: Run RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyAdminRuntimeBoundaryTest|PageControllerTest'
pnpm --filter='@woocommerce/admin-library' test:js -- client/task-lists/setup-task-list/components/task-headers/index.test.ts
```

Expected RED: the boundary test still finds the deprecated files/references, the PageController test finds no legacy action redirect, and the task-header map still contains `woocommerce-payments`.

### Task 2: Remove the Deprecated WooPayments Task Surface

**Files:**
- Delete: `plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/WooCommercePayments.php`
- Delete: `plugins/woocommerce/tests/legacy/unit-tests/woocommerce-admin/features/onboarding-tasks/tasks/woocommerce-payments.php`
- Delete: `plugins/woocommerce/client/admin/client/task-lists/fills/woocommerce-payments.tsx`
- Delete: `plugins/woocommerce/client/admin/client/task-lists/setup-task-list/components/task-headers/woocommerce-payments.js`
- Modify: `plugins/woocommerce/src/Admin/Features/OnboardingTasks/TaskLists.php`
- Modify: `plugins/woocommerce/client/admin/client/task-lists/fills/index.ts`
- Modify: `plugins/woocommerce/client/admin/client/task-lists/setup-task-list/components/task-headers/index.ts`
- Modify: `plugins/woocommerce/client/admin/client/typings/global.d.ts`

- [x] **Step 1: Remove the PHP task class and its init registration**

Delete `Tasks/WooCommercePayments.php` and remove `'WooCommercePayments'` from `TaskLists::DEFAULT_TASKS`. Do not change the active setup list tasks, because `Payments` already owns that flow.

- [x] **Step 2: Remove the task fill and task header**

Delete the dedicated WooPayments task fill and header component, remove the fill import from `task-lists/fills/index.ts`, and remove the `'woocommerce-payments'` header mapping from `task-headers/index.ts`.

- [x] **Step 3: Remove the obsolete admin setting typing**

Delete `wcpayWelcomePageIncentive` from `client/admin/client/typings/global.d.ts` because the deleted task header is its only remaining consumer. Keep `wcpay_welcome_page_connect_nonce`; it is still used by live payments settings flows.

- [x] **Step 4: Remove the legacy task test**

Delete the legacy PHPUnit file that directly constructs the removed task class.

### Task 3: Remove Note Creation and Preserve Legacy Entry Points

**Files:**
- Delete: `plugins/woocommerce/src/Internal/Admin/Notes/WooCommercePayments.php`
- Delete: `plugins/woocommerce/tests/php/src/Internal/Admin/Notes/WooCommercePaymentsTest.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Events.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/FeaturePlugin.php`
- Modify: `plugins/woocommerce/src/Admin/Notes/DeprecatedNotes.php`
- Modify: `plugins/woocommerce/src/Admin/PageController.php`
- Modify: `plugins/woocommerce/tests/legacy/unit-tests/woocommerce-admin/notes/class-wc-tests-marketing-notes.php`

- [x] **Step 1: Stop creating and loading the deprecated WooPayments note**

Delete the note class, remove it from `Events::$note_classes_to_added_or_updated`, and remove `new WooCommercePayments()` from `FeaturePlugin::includes()`.

- [x] **Step 2: Delete stale stored notes by name**

In `Events.php`, add a small deprecated-note cleanup path that deletes notes named:

```php
'wc-admin-woocommerce-payments'
```

Run it from the daily admin event flow so existing stored notes are removed after the upgrade.

- [x] **Step 3: Preserve the old task/action URLs as redirects**

In `PageController.php`:

1. Keep `task=woocommerce-payments` redirecting to the Payments settings page.
2. Add a redirect for `action=setup-woocommerce-payments` to the same settings URL.
3. Update the method comments so the old WooPayments task/action are explicitly described as deprecated compatibility aliases, not active onboarding flow.

- [x] **Step 4: Remove the deprecated note facade and stale note test usage**

Delete `WC_Admin_Notes_WooCommerce_Payments` from `DeprecatedNotes.php`. In `class-wc-tests-marketing-notes.php`, remove the test that uses `WooCommercePayments::possibly_add_note()` and keep the generic marketing-note coverage that still applies.

### Task 4: Verify, Changelog, Commit, and Log

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3u-remove-woopayments-onboarding-surface`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b3u.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [x] **Step 1: Add changelog**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-b3u-remove-woopayments-onboarding-surface
```

with:

```text
Significance: patch
Type: dev
Comment: Remove the deprecated WooPayments onboarding task and note surface while preserving legacy redirects to Payments settings.
```

- [x] **Step 2: Run focused gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyAdminRuntimeBoundaryTest|PageControllerTest'
pnpm --filter='@woocommerce/admin-library' test:js -- client/task-lists/setup-task-list/components/task-headers/index.test.ts
```

Require both to pass.

- [x] **Step 3: Run static and source-boundary gates**

Run:

```bash
php -l plugins/woocommerce/src/Admin/PageController.php
php -l plugins/woocommerce/src/Admin/Features/OnboardingTasks/TaskLists.php
php -l plugins/woocommerce/src/Internal/Admin/Events.php
php -l plugins/woocommerce/src/Internal/Admin/FeaturePlugin.php
php -l plugins/woocommerce/src/Admin/Notes/DeprecatedNotes.php
php -l plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php
php -l plugins/woocommerce/tests/php/src/Admin/PageControllerTest.php
php -l plugins/woocommerce/tests/legacy/unit-tests/woocommerce-admin/notes/class-wc-tests-marketing-notes.php
composer exec -- phpstan analyse src/Admin/PageController.php src/Admin/Features/OnboardingTasks/TaskLists.php src/Internal/Admin/Events.php src/Internal/Admin/FeaturePlugin.php src/Admin/Notes/DeprecatedNotes.php --memory-limit=2G
pnpm --filter='@woocommerce/admin-library' exec eslint client/task-lists/setup-task-list/components/task-headers/index.ts client/task-lists/setup-task-list/components/task-headers/index.test.ts client/task-lists/fills/index.ts --ext=js,ts,tsx
pnpm --filter='@woocommerce/admin-library' lint:lang:types
git diff --check
```

Also rerun production scans for:

```text
WooCommercePayments::class
new WooCommercePayments()
woocommerce-admin-task-wcpay-page
wcpayWelcomePageIncentive
```

and confirm only the intended compatibility strings remain:

```text
task=woocommerce-payments
action=setup-woocommerce-payments
wc-admin-woocommerce-payments
```

- [x] **Step 4: Stage, commit, and log**

Stage only B3u code changes, run `git diff --cached --check`, commit with a conventional message, run post-commit `pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch`, then update the implementation log and external staging log with RED/GREEN evidence and the git range.
