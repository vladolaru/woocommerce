---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 12:27
status: draft
---

# Core Native Payments B3m Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize WooPayments admin settings access to legacy runtime state and remove provider-local container/service lookups from the WooPayments admin provider path.

**Architecture:** `WooPayments` payment-provider instances are constructed by `PaymentsProviders`, not directly by the WooCommerce container, so B3m cannot simply add an `init()` dependency to `WooPayments`. Instead, `PaymentsProviders` receives `ContainerInterface` and `WooPaymentsLegacyRuntime`, configures constructed `WooPayments` instances with the runtime plus lazy admin service/REST resolvers, and `WooPayments` calls those collaborators through explicit resolver methods. The same slice expands `WooPaymentsLegacyRuntime` to own WooPayments mode, account status, supported countries, and onboarding test-mode reset helpers, then rewires `WooPayments` and `WooPaymentsService` away from direct WooPayments class checks for those concerns.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Admin/Settings` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Source-Boundary and Runtime RED Tests

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPaymentsTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/LegacyRuntimeProxy.php`

- [ ] **Step 1: Add WooPayments provider boundary coverage**

Add a source-boundary test proving `WooPayments.php` no longer calls `wc_get_container()->get()` and no longer directly probes `WC_Payments::mode`, `WC_Payments_Account::get_connect_url`, `wcpay_get_container`, or `WC_Payments_Utils::supported_countries`.

- [ ] **Step 2: Add WooPayments service boundary coverage**

Add a source-boundary test proving `WooPaymentsService.php` no longer directly calls `class_exists( 'WC_Payments_Onboarding_Service' )`, `class_exists( '\WC_Payments_Utils' )`, or `\WC_Payments_Utils::supported_countries`.

- [ ] **Step 3: Add runtime helper coverage**

Extend `WooPaymentsLegacyRuntimeTest` and `LegacyRuntimeProxy` to cover mode booleans, account status data, supported countries, and resetting the WooPayments onboarding test-mode option. The tests should fail until the runtime exposes those helpers.

- [ ] **Step 4: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsTest::test_admin_runtime_access_is_injected|WooPaymentsServiceTest::test_legacy_runtime_access_is_centralized|WooPaymentsLegacyRuntimeTest'`

Expected: FAIL because the source-boundary assertions still find direct provider/service lookups and the new runtime helpers do not exist.

### Task 2: Expand WooPaymentsLegacyRuntime

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/LegacyRuntimeProxy.php`

- [ ] **Step 1: Add mode helpers**

Add `is_test_mode()`, `is_dev_mode()`, and `is_test_mode_onboarding()` methods that call the WooPayments mode service defensively and return `null` when the runtime or method is unavailable.

- [ ] **Step 2: Add account and country helpers**

Add `get_account_status_data()` and `get_supported_countries()` methods. `get_account_status_data()` should return `array<string,mixed>|null`; `get_supported_countries()` should return the raw supported-countries array or `null`, trying both leading-slash and no-leading-slash `WC_Payments_Utils` static mock keys for test compatibility.

- [ ] **Step 3: Add onboarding option reset helper**

Add `reset_onboarding_test_mode_option()` to update `WC_Payments_Onboarding_Service::TEST_MODE_OPTION` to `no` when that class constant is available. Swallow lookup/update errors.

### Task 3: Rewire WooPayments Admin Provider

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPaymentsTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProvidersTest.php`

- [ ] **Step 1: Configure WooPayments provider instances**

Inject optional `ContainerInterface` and `WooPaymentsLegacyRuntime` into `PaymentsProviders::init()`. When constructing a `WooPayments` provider and both collaborators are available, call a new `WooPayments::set_admin_runtime_collaborators()` method with the runtime and lazy resolvers for `WooPaymentsRestController` and `WooPaymentsService`.

- [ ] **Step 2: Remove provider-local container lookups**

Replace the three `wc_get_container()->get()` calls in `WooPayments.php` with private `get_rest_controller()` and `get_service()` methods backed by the configured resolvers. Keep existing error logging and fail-open behavior when the collaborators are unavailable.

- [ ] **Step 3: Route provider legacy probes through runtime**

Use `WooPaymentsLegacyRuntime` for test/dev/test-onboarding mode checks, connect URL lookup, account status data, and supported country data. Preserve parent fallbacks and existing onboarding URL query-argument behavior.

### Task 4: Rewire WooPaymentsService Legacy Checks

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php`

- [ ] **Step 1: Inject WooPaymentsLegacyRuntime**

Add `WooPaymentsLegacyRuntime` to `WooPaymentsService::init()` and update service test initializers. Keep `WooPaymentsOnboardingAdapter` injected.

- [ ] **Step 2: Replace direct onboarding option resets**

Replace the direct `WC_Payments_Onboarding_Service::TEST_MODE_OPTION` checks in `reset_onboarding()` and `disable_test_account()` with `WooPaymentsLegacyRuntime::reset_onboarding_test_mode_option()`.

- [ ] **Step 3: Replace direct supported-country lookup**

Replace the `\WC_Payments_Utils::supported_countries` direct lookup in `get_onboarding_kyc_fields()` with `WooPaymentsLegacyRuntime::get_supported_countries()`.

### Task 5: Verification, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3m-woopayments-admin-runtime`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsTest|WooPaymentsServiceTest|WooPaymentsLegacyRuntimeTest|PaymentsProvidersTest'`

Expected: PASS.

- [ ] **Step 2: Run static gates**

Run syntax checks for touched production/test files, PHPStan for touched production files, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, and `git diff --cached --check`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 3: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then append B3m evidence above the implementation-log append marker and staging-log append marker. Include the RED failure, focused GREEN result, static gates, commit hash, and git range.
