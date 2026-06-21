---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 13:41
status: draft
---

# Core Native Payments B3r Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move remaining admin WooPayments extension-version checks behind `WooPaymentsLegacyRuntime`.

**Architecture:** B3q centralized the remaining legacy admin class/static probes but left two direct `WCPAY_VERSION_NUMBER` checks in the admin provider and onboarding service. B3r adds a small fail-closed version helper to `WooPaymentsLegacyRuntime`, rewires those callers to depend on the injected runtime boundary, and expands the source-boundary test so future admin code cannot reintroduce direct extension-version constant reads outside the runtime seam.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: RED Tests for Extension Version Boundary

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php`

- [ ] **Step 1: Add runtime version helper RED coverage**

Extend `WooPaymentsLegacyRuntimeTest` with tests for `is_extension_version_less_than( string $minimum_version ): ?bool`. Cover a defined `WCPAY_VERSION_NUMBER` below the minimum returning `true`, a defined version equal/newer than the minimum returning `false`, an undefined constant returning `null`, and a non-scalar constant returning `null`. Clear Jetpack `Constants` after each mutation.

- [ ] **Step 2: Extend source-boundary RED coverage**

Update `WooPaymentsLegacyAdminRuntimeBoundaryTest` so it asserts `WCPAY_VERSION_NUMBER` is absent from `src/Internal/Admin/Settings/PaymentsProviders/WooPayments.php` and `src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`. Keep `WooPaymentsLegacyRuntime` as the intentional owner of direct extension constant reads.

- [ ] **Step 3: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsLegacyAdminRuntimeBoundaryTest'`

Expected: FAIL because `WooPaymentsLegacyRuntime::is_extension_version_less_than()` does not exist yet and both admin settings files still read `WCPAY_VERSION_NUMBER` directly.

### Task 2: Runtime Helper and Admin Rewire

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`

- [ ] **Step 1: Add the runtime version helper**

Add `WooPaymentsLegacyRuntime::is_extension_version_less_than( string $minimum_version ): ?bool`. It should return `null` when `WCPAY_VERSION_NUMBER` is undefined or non-scalar, and otherwise return the result of `version_compare( (string) $version, $minimum_version, '<' )`.

- [ ] **Step 2: Rewire `WooPayments::get_details()`**

Replace the direct `Constants::is_defined( 'WCPAY_VERSION_NUMBER' )` and `Constants::get_constant()` version comparison with `$this->legacy_runtime?->is_extension_version_less_than( WooPaymentsService::EXTENSION_MINIMUM_VERSION )`. Preserve existing behavior: only skip in-context onboarding when the installed extension version is known and below the minimum.

- [ ] **Step 3: Rewire `WooPaymentsService::check_if_onboarding_action_is_acceptable()`**

Use the service's existing onboarding-runtime collaborator to check `is_extension_version_less_than( self::EXTENSION_MINIMUM_VERSION )`. Preserve existing behavior: throw `woocommerce_woopayments_onboarding_extension_version` only when the installed extension version is known and below the minimum; do not throw when native runtime is active or the legacy extension version is unknown.

### Task 3: Verification, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3r-woopayments-version-runtime`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsLegacyAdminRuntimeBoundaryTest|WooPaymentsTest::test_get_details|WooPaymentsServiceTest'`

Expected: PASS.

- [ ] **Step 2: Run broader admin/runtime regression tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsTest|WooPaymentsServiceTest|WooPaymentsLegacyRuntimeTest|WooPaymentsLegacyAdminRuntimeBoundaryTest|PaymentsProvidersTest'`

Expected: PASS.

- [ ] **Step 3: Run static gates**

Run syntax checks for all touched production/test PHP files, PHPStan for touched production files, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, `git diff --cached --check`, and post-commit `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:branch`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 4: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then update the implementation log above `IMPLEMENTATION_LOG_APPEND_POINT` and append the staging-log evidence for B3r. Include RED failures, focused and broader GREEN results, static gates, commit hash, and git range.
