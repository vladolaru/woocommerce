---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 13:11
status: draft
---

# Core Native Payments B3p Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the remaining WooPayments onboarding-service admin settings context constants behind `WooPaymentsLegacyRuntime`.

**Architecture:** B3m moved most WooPayments admin provider runtime probes into `WooPaymentsLegacyRuntime`, but `PaymentsProviders\WooPayments::get_onboarding_url()` still reads `WC_Payments_Onboarding_Service::FROM_WCADMIN_PAYMENTS_SETTINGS` and `SOURCE_WCADMIN_SETTINGS_PAGE` directly. B3p adds one runtime helper for those admin onboarding context values with Core-owned fallbacks, rewires the provider to consume that helper, and extends source-boundary coverage so direct WooPayments onboarding-service constant reads do not drift back into the admin provider.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Runtime and Provider RED Tests

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPaymentsTest.php`

- [ ] **Step 1: Add runtime onboarding-context coverage**

Extend `WooPaymentsLegacyRuntimeTest` with coverage for a new `get_admin_onboarding_context()` helper. The test should prove the runtime returns WooPayments constants when `WC_Payments_Onboarding_Service::FROM_WCADMIN_PAYMENTS_SETTINGS` and `WC_Payments_Onboarding_Service::SOURCE_WCADMIN_SETTINGS_PAGE` are available, and returns `WCADMIN_PAYMENT_SETTINGS` plus `wcadmin-settings-page` fallbacks when they are not.

- [ ] **Step 2: Add provider source-boundary coverage**

Extend `WooPaymentsTest::test_admin_runtime_access_is_injected()` to assert `WooPayments.php` no longer contains `WC_Payments_Onboarding_Service::FROM_WCADMIN_PAYMENTS_SETTINGS` or `WC_Payments_Onboarding_Service::SOURCE_WCADMIN_SETTINGS_PAGE`.

- [ ] **Step 3: Add provider onboarding URL behavior coverage**

Add a provider test that sets the WooPayments onboarding constants through `Automattic\Jetpack\Constants`, calls `get_onboarding_url()` for a connected fake WooPayments gateway, and asserts the returned URL contains the runtime-provided `from` and `source` values.

- [ ] **Step 4: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsTest::test_admin_runtime_access_is_injected|WooPaymentsTest::test_get_onboarding_url_uses_runtime_admin_onboarding_context'`

Expected: FAIL because `WooPaymentsLegacyRuntime::get_admin_onboarding_context()` does not exist and `WooPayments.php` still reads the WooPayments onboarding constants directly.

### Task 2: Implement Runtime Helper and Provider Rewire

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments.php`

- [ ] **Step 1: Add `get_admin_onboarding_context()`**

Add `WooPaymentsLegacyRuntime::get_admin_onboarding_context(): array` that returns `array( 'from' => ..., 'source' => ... )`, using Jetpack `Constants::get_constant()` only when the matching WooPayments onboarding-service constant is defined and falling back to Core defaults otherwise.

- [ ] **Step 2: Rewire `get_onboarding_url()`**

Update `PaymentsProviders\WooPayments::get_onboarding_url()` so the `from` and `source` params come from `WooPaymentsLegacyRuntime::get_admin_onboarding_context()` when the runtime collaborator is available, and from the same Core fallback values when not. Keep `redirect_to_settings_page=true` and the existing account/test-drive routing unchanged.

### Task 3: Verification, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3p-woopayments-onboarding-context-runtime`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsTest::test_admin_runtime_access_is_injected|WooPaymentsTest::test_get_onboarding_url_uses_runtime_admin_onboarding_context|WooPaymentsTest::test_get_onboarding_url_with_connected_account'`

Expected: PASS.

- [ ] **Step 2: Run broader admin provider regression tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsTest|WooPaymentsLegacyRuntimeTest|PaymentsProvidersTest'`

Expected: PASS.

- [ ] **Step 3: Run static gates**

Run syntax checks for touched production/test files, PHPStan for touched production files, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, `git diff --cached --check`, and post-commit `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:branch`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 4: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then append B3p evidence above the implementation-log append marker and staging-log append marker. Include RED failures, focused and broader GREEN results, static gates, commit hash, and git range.
