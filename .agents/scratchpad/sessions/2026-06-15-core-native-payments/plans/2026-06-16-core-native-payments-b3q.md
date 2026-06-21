---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 13:28
status: draft
---

# Core Native Payments B3q Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the remaining legacy admin WooPayments runtime probes behind `WooPaymentsLegacyRuntime`.

**Architecture:** B3p left the intentional runtime seams in `WooPaymentsLegacyRuntime`, `NativePaymentsRuntimeArbiter`, `NativeWooPaymentsGateway`, and Subscriptions compatibility, but legacy admin code still directly probes `WC_Payments` and `WC_Payments_Utils`. B3q treats admin API plugin connect links, Blueprint payment gateway export, and onboarding tasks as one boundary chunk: add small runtime helper methods where Core needs gateway state or static WooPayments side effects, then rewire legacy admin callers through container-resolved `WooPaymentsLegacyRuntime` with fail-closed fallbacks.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Admin` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: RED Tests for Runtime Helpers and Legacy Admin Source Boundaries

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/LegacyRuntimeProxy.php`
- Create: `plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php`

- [ ] **Step 1: Add runtime gateway helper RED coverage**

Extend `WooPaymentsLegacyRuntimeTest` with tests for new runtime helpers: `is_gateway_connected(): ?bool`, `is_gateway_partially_onboarded(): ?bool`, and `hide_gateways_on_settings_page(): bool`. Use anonymous gateway objects with `is_connected()` and `is_account_partially_onboarded()` methods, assert `null` for absent runtime/missing methods, and add a `LegacyRuntimeProxy` counter for `WC_Payments::hide_gateways_on_settings_page()`.

- [ ] **Step 2: Add legacy admin source-boundary RED coverage**

Create `WooPaymentsLegacyAdminRuntimeBoundaryTest` that reads the production source files and asserts direct runtime symbols are absent:

```php
$this->assertStringNotContainsString( "class_exists( 'WC_Payments' )", $source );
$this->assertStringNotContainsString( "class_exists( '\\WC_Payments' )", $source );
$this->assertStringNotContainsString( '\\WC_Payments::get_gateway', $source );
$this->assertStringNotContainsString( '\\WC_Payments::hide_gateways_on_settings_page', $source );
$this->assertStringNotContainsString( '\\WC_Payments_Utils::supported_countries', $source );
```

Target files: `src/Admin/API/Plugins.php`, `src/Admin/Features/Blueprint/Exporters/ExportWCPaymentGateways.php`, `src/Admin/Features/OnboardingTasks/Init.php`, `src/Admin/Features/OnboardingTasks/Tasks/Payments.php`, and `src/Admin/Features/OnboardingTasks/Tasks/WooCommercePayments.php`.

- [ ] **Step 3: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsLegacyAdminRuntimeBoundaryTest'`

Expected: FAIL because the runtime helper methods do not exist yet and the legacy admin source files still contain direct WooPayments class/static references.

### Task 2: Runtime Helper Implementation

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/LegacyRuntimeProxy.php`

- [ ] **Step 1: Add gateway state helpers**

Add `WooPaymentsLegacyRuntime::is_gateway_connected(): ?bool` and `WooPaymentsLegacyRuntime::is_gateway_partially_onboarded(): ?bool`. Both methods should call the existing `get_gateway()` helper, verify the target method exists and is callable through the legacy proxy, then return the gateway method result cast to bool. Return `null` when the runtime, gateway, method, or call is unavailable.

- [ ] **Step 2: Add static hide helper**

Add `WooPaymentsLegacyRuntime::hide_gateways_on_settings_page(): bool`. It should fail closed when `is_loaded()` is false or `WC_Payments::hide_gateways_on_settings_page` is not callable, call the static method through `LegacyProxy::call_static()`, and return `true` when the call succeeds.

### Task 3: Rewire Legacy Admin Callers

**Files:**
- Modify: `plugins/woocommerce/src/Admin/API/Plugins.php`
- Modify: `plugins/woocommerce/src/Admin/Features/Blueprint/Exporters/ExportWCPaymentGateways.php`
- Modify: `plugins/woocommerce/src/Admin/Features/OnboardingTasks/Init.php`
- Modify: `plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/Payments.php`
- Modify: `plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/WooCommercePayments.php`

- [ ] **Step 1: Rewire `Plugins::connect_wcpay()`**

Import `WooPaymentsLegacyRuntime`, resolve it from `wc_get_container()`, and replace `class_exists( 'WC_Payments' )` with `null !== $runtime && $runtime->is_loaded()`. Preserve the existing WP_Error and connect URL shape.

- [ ] **Step 2: Rewire Blueprint gateway hiding**

Import `WooPaymentsLegacyRuntime`, resolve it from `wc_get_container()`, and replace the direct `WC_Payments::hide_gateways_on_settings_page()` call with `$runtime->hide_gateways_on_settings_page()`. Fail closed when the runtime is unavailable.

- [ ] **Step 3: Remove the dead direct probe in onboarding `Init::get_settings()`**

`Init::get_settings()` currently computes `$wc_pay_is_connected` through `WC_Payments::get_gateway()` and then returns the untouched `$settings` array. Remove that dead probe so the method still returns `array()` without direct WooPayments access.

- [ ] **Step 4: Rewire the active and supported-country checks in `Tasks\Payments`**

Import `WooPaymentsLegacyRuntime`, add a private `get_woopayments_runtime(): ?WooPaymentsLegacyRuntime`, replace `is_woopayments_active()` with a runtime `is_loaded()` check, and replace the direct `WC_Payments_Utils::supported_countries()` branch with `$runtime->get_supported_countries()` when available. Preserve the fallback to `DefaultPaymentGateways::get_wcpay_countries()`.

- [ ] **Step 5: Rewire deprecated `Tasks\WooCommercePayments`**

Import `WooPaymentsLegacyRuntime`, add a private static runtime resolver, replace `is_wcpay_active()` with `is_loaded()`, replace `is_connected()` with `is_gateway_connected() ?? false`, replace `is_account_partially_onboarded()` with `is_gateway_partially_onboarded() ?? false`, and remove the private direct gateway accessor.

### Task 4: Verification, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3q-legacy-admin-runtime`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsLegacyAdminRuntimeBoundaryTest'`

Expected: PASS.

- [ ] **Step 2: Run broader legacy admin regression tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsLegacyAdminRuntimeBoundaryTest|ExperimentalShippingRecommendationTest|Automattic\\WooCommerce\\Tests\\Internal\\Admin\\Notes\\WooCommercePaymentsTest|PaymentsTest|PaymentsProvidersTest'`

Expected: PASS.

- [ ] **Step 3: Run static gates**

Run syntax checks for all touched production/test PHP files, PHPStan for touched production files, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, `git diff --cached --check`, and post-commit `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:branch`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 4: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then append B3q evidence above the implementation-log append marker and staging-log append marker. Include RED failures, focused and broader GREEN results, static gates, commit hash, and git range.
