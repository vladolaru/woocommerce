---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 12:47
status: draft
---

# Core Native Payments B3n Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize WooPayments account-cache and loaded-runtime checks used by admin menu ownership and payments suggestion incentives.

**Architecture:** `WooPaymentsLegacyRuntime` becomes the single owner for reading the transitional WooPayments account cache option and interpreting whether the plugin runtime is loaded, has cached account data, or appears onboarded from cache. `PaymentsController` receives the runtime directly through `init()` and the suggestion incentives factory receives it once, then passes it to WooPayments-specific incentive providers constructed outside the container. This keeps the runtime seam explicit while avoiding provider-local container lookups or duplicated `class_exists( '\WC_Payments' )` and `get_option( 'wcpay_account_data' )` probes.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Admin/Settings`, `plugins/woocommerce/src/Internal/Admin/Suggestions`, and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`; PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`; PHPStan; PHPCS.

---

### Task 1: Runtime Account Cache RED Tests

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/LegacyRuntimeProxy.php`

- [ ] **Step 1: Add account-cache helper coverage**

Add tests for `WooPaymentsLegacyRuntime::has_cached_account_data()` and `WooPaymentsLegacyRuntime::is_account_onboarded_from_cache()`. Cover no account data, cached account ID only, `details_submitted` as true-like data, and non-array option payloads.

- [ ] **Step 2: Extend the runtime proxy**

Extend `LegacyRuntimeProxy` so tests can inject a `wcpay_account_data` option payload and so `call_function( 'get_option', 'wcpay_account_data', array() )` returns that payload.

- [ ] **Step 3: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest'`

Expected: FAIL because `has_cached_account_data()` and `is_account_onboarded_from_cache()` do not exist yet.

### Task 2: Admin Call Site RED Tests

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsControllerTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Suggestions/Incentives/WooPaymentsTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Suggestions/PaymentsExtensionSuggestionIncentivesTest.php`

- [ ] **Step 1: Add payments controller boundary coverage**

Create `PaymentsControllerTest` with a source-boundary assertion that `PaymentsController.php` no longer contains `class_exists( '\WC_Payments' )` or `wcpay_account_data`, plus behavior coverage proving `add_menu()` does not add WooCommerce Core's Payments menu when the injected runtime reports an onboarded account from cache.

- [ ] **Step 2: Add WooPayments incentive runtime coverage**

Update `WooPaymentsTest` so the SUT receives an injected `WooPaymentsLegacyRuntime` instead of mocking `is_extension_active()`. Add a source-boundary assertion that the incentive provider no longer directly contains `class_exists( '\WC_Payments' )` or `wcpay_account_data`.

- [ ] **Step 3: Add incentives factory wiring coverage**

Update `PaymentsExtensionSuggestionIncentivesTest` to assert the WooPayments incentive instance receives the same runtime injected into `PaymentsExtensionSuggestionIncentives::init()`. Keep unknown suggestion behavior unchanged.

- [ ] **Step 4: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentsControllerTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\Suggestions\\\\Incentives\\\\WooPaymentsTest|PaymentsExtensionSuggestionIncentivesTest'`

Expected: FAIL because the controller and incentives code still perform direct WooPayments class/option probes and the factory does not inject the runtime yet.

### Task 3: Implement Runtime Account Cache Helpers

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/LegacyRuntimeProxy.php`

- [ ] **Step 1: Add `get_cached_account_data()`**

Add a private runtime helper that calls `get_option( 'wcpay_account_data', array() )` through `LegacyProxy`, validates that the payload and nested `data` value are arrays, and returns the nested data array or an empty array. Swallow legacy lookup errors and return an empty array.

- [ ] **Step 2: Add public account-cache predicates**

Add `has_cached_account_data(): bool` based on a non-empty `account_id`, and `is_account_onboarded_from_cache(): bool` based on a non-empty `account_id` plus boolean-normalized `details_submitted`.

### Task 4: Rewire PaymentsController

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsController.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsControllerTest.php`

- [ ] **Step 1: Inject `WooPaymentsLegacyRuntime`**

Add `WooPaymentsLegacyRuntime` to `PaymentsController::init( Payments $payments, WooPaymentsLegacyRuntime $woopayments_runtime )` and store it in a private property.

- [ ] **Step 2: Replace direct account-cache probing**

Replace `is_woopayments_account_onboarded()` with a runtime-backed implementation that returns false unless the WooPayments runtime is loaded and `is_account_onboarded_from_cache()` is true.

- [ ] **Step 3: Keep menu behavior stable**

Preserve the existing behavior where WooCommerce Core skips adding its Payments menu when WooPayments owns the menu, and removes the WooPayments connect page menu otherwise.

### Task 5: Rewire WooPayments Incentives and Factory

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/Suggestions/PaymentsExtensionSuggestionIncentives.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Suggestions/Incentives/WooPayments.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Suggestions/Incentives/WooPaymentsTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Suggestions/PaymentsExtensionSuggestionIncentivesTest.php`

- [ ] **Step 1: Add factory runtime injection**

Add `PaymentsExtensionSuggestionIncentives::init( WooPaymentsLegacyRuntime $woopayments_runtime )` and store the runtime for provider construction.

- [ ] **Step 2: Pass runtime to WooPayments incentives**

Update `get_incentive_instance()` so WooPayments incentive providers receive the runtime at construction time. Keep the generic constructor path for non-WooPayments incentive classes to avoid overfitting the whole factory to one provider.

- [ ] **Step 3: Replace direct incentive probes**

Update `Incentives\WooPayments` to accept `WooPaymentsLegacyRuntime` in its constructor, use `is_loaded()` for extension-active checks, and use `has_cached_account_data()` for account-cache checks.

### Task 6: Verification, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3n-woopayments-account-cache-runtime`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|PaymentsControllerTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\Suggestions\\\\Incentives\\\\WooPaymentsTest|PaymentsExtensionSuggestionIncentivesTest'`

Expected: PASS.

- [ ] **Step 2: Run broader admin payments regression tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentsControllerTest|PaymentsExtensionSuggestionIncentivesTest|PaymentsExtensionSuggestionsTest|WooPaymentsLegacyRuntimeTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\Suggestions\\\\Incentives\\\\WooPaymentsTest'`

Expected: PASS.

- [ ] **Step 3: Run static gates**

Run syntax checks for touched production/test files, PHPStan for touched production files, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, and `git diff --cached --check`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 4: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then append B3n evidence above the implementation-log append marker and staging-log append marker. Include RED failures, focused and broader GREEN results, static gates, commit hash, and git range.
