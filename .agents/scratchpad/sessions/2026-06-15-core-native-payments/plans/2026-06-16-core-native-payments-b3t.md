---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 14:05
status: final
last_updated: 2026-06-16 14:17
---

# B3t Deprecated WcPay Welcome Page Surface Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the deprecated WcPay welcome-page route and PHP incentive-note/task surface without breaking the shared WooPayments connect flows used by current payments settings.

**Architecture:** Treat the deprecated welcome page as a full surface, not a single PHP class. Delete the route bundle, feature flag, PHP class, welcome-page notes, and deprecated task redirect branch; keep the shared `wcpay_welcome_page_connect_nonce` and `wcpayWelcomePageIncentive` setting for current payments settings/task-header consumers unless this chunk explicitly migrates those consumers.

**Tech Stack:** WooCommerce Core PHP, WC Admin React/Jest, PHPUnit, source-boundary regression tests, PHPStan baseline shrink.

**Result:** Removed the deprecated welcome-page surface in Core while intentionally retaining the shared connect nonce and incentive setting consumed by live payments settings and task-header flows. The generated PHP feature config already had no `wc-pay-welcome-page` entry, so the route cleanup lived in the client bundle and config sources.

---

### Task 1: Lock Removal With RED Tests

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php`
- Modify: `plugins/woocommerce/client/admin/client/layout/test/controller.test.js`

- [x] **Step 1: Add PHP source-boundary removal coverage**

Add assertions that these files no longer exist after removal:

```php
'src/Internal/Admin/WcPayWelcomePage.php',
'src/Internal/Admin/Notes/PaymentsMoreInfoNeeded.php',
'src/Internal/Admin/Notes/PaymentsRemindMeLater.php',
```

Also assert the PHP source tree no longer contains `WcPayWelcomePage::instance()`, `PaymentsMoreInfoNeeded::class`, `PaymentsRemindMeLater::class`, or `admin.php?page=wc-admin&path=/wc-pay-welcome-page`.

- [x] **Step 2: Add admin route removal coverage**

In `controller.test.js`, import `getPages`, force `window.wcAdminFeatures['wc-pay-welcome-page'] = true`, call `getPages()`, and assert no page has `path === '/wc-pay-welcome-page'`.

- [x] **Step 3: Run RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyAdminRuntimeBoundaryTest
pnpm --filter='@woocommerce/admin-library' test:js -- client/layout/test/controller.test.js
```

Expected RED: PHP source-boundary test fails because the deprecated files and call sites still exist; JS test fails because `getPages()` still registers `/wc-pay-welcome-page`.

### Task 2: Remove the Deprecated PHP Surface

**Files:**
- Delete: `plugins/woocommerce/src/Internal/Admin/WcPayWelcomePage.php`
- Delete: `plugins/woocommerce/src/Internal/Admin/Notes/PaymentsMoreInfoNeeded.php`
- Delete: `plugins/woocommerce/src/Internal/Admin/Notes/PaymentsRemindMeLater.php`
- Delete: `plugins/woocommerce/tests/php/src/Internal/Admin/WcPayWelcomePageTest.php`
- Modify: `plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/WooCommercePayments.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Events.php`
- Modify: `plugins/woocommerce/phpstan-baseline.neon`

- [x] **Step 1: Remove task redirect to the welcome page**

In `WooCommercePayments::get_action_url()`, delete the `WcPayWelcomePage::instance()->has_incentive()` branch and the `use Automattic\WooCommerce\Internal\Admin\WcPayWelcomePage;` import. When WooPayments is unsupported/inactive and no active plugin flow applies, let the method fall through to the existing install suggestion URL.

- [x] **Step 2: Remove note classes from Events**

Delete the imports for `PaymentsMoreInfoNeeded` and `PaymentsRemindMeLater`, remove them from `$note_classes_to_added_or_updated`, and remove their `delete_if_not_applicable()` calls from `possibly_delete_notes()`.

- [x] **Step 3: Delete deprecated PHP files and tests**

Delete the three deprecated PHP production files and `WcPayWelcomePageTest.php`.

- [x] **Step 4: Shrink PHPStan baseline**

Remove baseline entries for deleted files/classes and the `WcPayWelcomePage` method call warning in `WooCommercePayments.php`.

### Task 3: Remove the Deprecated Admin Route Bundle

**Files:**
- Delete: `plugins/woocommerce/client/admin/client/payments-welcome/`
- Modify: `plugins/woocommerce/client/admin/client/layout/controller.js`
- Modify: `plugins/woocommerce/client/admin/client/typings/global.d.ts`
- Modify: `plugins/woocommerce/client/admin/config/core.json`
- Modify: `plugins/woocommerce/client/admin/config/development.json`
- Modify: `plugins/woocommerce/includes/react-admin/feature-config.php`

- [x] **Step 1: Remove route import and registration**

Delete the `WCPaymentsWelcomePage` lazy import and the `if ( window.wcAdminFeatures[ 'wc-pay-welcome-page' ] )` page registration from `controller.js`.

- [x] **Step 2: Remove feature flag entries**

Remove `wc-pay-welcome-page` from the admin config JSON files, generated PHP feature config, and `Window['wcAdminFeatures']` typings.

- [x] **Step 3: Delete the welcome-page client bundle**

Delete all files under `client/admin/client/payments-welcome/`, including tests and SVG assets.

### Task 4: Verify, Changelog, Commit, and Log

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3t-remove-wcpay-welcome-page`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [x] **Step 1: Add changelog**

Create a WooCommerce changelog entry with `Significance: patch`, `Type: dev`, and `Comment: Remove the deprecated WooPayments welcome page route and inbox-note surface.`

- [x] **Step 2: Run focused gates**

Run the RED commands again and require both to pass. Also run source scans for `WcPayWelcomePage`, `/wc-pay-welcome-page`, `PaymentsMoreInfoNeeded`, `PaymentsRemindMeLater`, and `client/admin/client/payments-welcome`.

- [x] **Step 3: Run static gates**

Run `php -l` for touched PHP files that still exist, PHPStan for touched production PHP files, scoped PHPCS for touched PHP files that still exist, admin-library JS lint for touched JS/TS files, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `pnpm --filter=@woocommerce/plugin-woocommerce lint:js:changes` if available or the closest branch JS lint command, and `git diff --check`.

- [x] **Step 4: Commit and log**

Stage only B3t changes, run staged gates, commit with a conventional message, then update the implementation log and staging log with RED/GREEN evidence and the git range.
