---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 13:02
status: draft
---

# Core Native Payments B3o Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route legacy WooPayments promotion and welcome-page active-runtime checks through `WooPaymentsLegacyRuntime` instead of direct WooPayments class or constant probes.

**Architecture:** B3o groups the deprecated promotion surfaces that suppress WooPayments promos when WooPayments is already active: `WcPayWelcomePage`, `WCPayPromotion\Init`, and `Notes\WooCommercePayments`. The instance-style welcome page receives `PaymentsExtensionSuggestionIncentives` and `WooPaymentsLegacyRuntime` through optional constructor collaborators so tests can avoid the container while `instance()` preserves existing behavior. The two static legacy classes use small private runtime accessors backed by `wc_get_container()->get( WooPaymentsLegacyRuntime::class )` with fail-closed exception handling, while plugin installation checks in the inbox note continue to use WordPress `validate_plugin()` because that is installation state, not loaded-runtime state.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Admin`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Promotion Runtime RED Tests

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/Admin/WcPayWelcomePageTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/WCPayPromotion/InitTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Admin/Notes/WooCommercePaymentsTest.php`

- [ ] **Step 1: Add welcome-page source and behavior coverage**

Create `WcPayWelcomePageTest` with a source-boundary assertion that `WcPayWelcomePage.php` no longer directly contains `class_exists( '\WC_Payments' )`, plus behavior coverage proving `has_incentive()` returns false and does not ask for incentives when the injected runtime reports WooPayments loaded.

- [ ] **Step 2: Add promoted-gateway source and behavior coverage**

Extend `WCPayPromotion\InitTest` with a source-boundary assertion that `Init.php` no longer directly contains `class_exists( '\WC_Payments' )`, plus behavior coverage proving `can_show_promotion()` returns false through the runtime when WooPayments is loaded.

- [ ] **Step 3: Add inbox-note source and behavior coverage**

Create `Notes\WooCommercePaymentsTest` with a source-boundary assertion that `WooCommercePayments.php` no longer directly contains `defined( 'WC_Payments' )`, plus reflection-backed coverage proving `is_installed()` returns true when the runtime reports WooPayments loaded.

- [ ] **Step 4: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WcPayWelcomePageTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\WCPayPromotion\\\\InitTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\Notes\\\\WooCommercePaymentsTest'`

Expected: FAIL because the source-boundary assertions still find direct WooPayments checks and the new constructor/runtime paths do not exist yet.

### Task 2: Rewire WcPayWelcomePage

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/WcPayWelcomePage.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Admin/WcPayWelcomePageTest.php`

- [ ] **Step 1: Add optional constructor collaborators**

Update `__construct()` to accept `?PaymentsExtensionSuggestionIncentives $suggestion_incentives = null` and `?WooPaymentsLegacyRuntime $legacy_runtime = null`, defaulting each collaborator to the WooCommerce container to preserve `WcPayWelcomePage::instance()` behavior.

- [ ] **Step 2: Replace direct active check**

Store the runtime in a private property and update `is_wcpay_active()` to call `$this->legacy_runtime->is_loaded()`.

### Task 3: Rewire WCPayPromotion Init

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/WCPayPromotion/Init.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/WCPayPromotion/InitTest.php`

- [ ] **Step 1: Add runtime import and helper**

Import `WooPaymentsLegacyRuntime` and add a private static `is_woopayments_loaded(): bool` helper that resolves the runtime from the WooCommerce container, returns `is_loaded()`, and catches `\Throwable` by returning false.

- [ ] **Step 2: Replace direct promotion active check**

Update `can_show_promotion()` to use the helper instead of `class_exists( '\WC_Payments' )`.

### Task 4: Rewire WooCommercePayments Note

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Admin/Notes/WooCommercePayments.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Admin/Notes/WooCommercePaymentsTest.php`

- [ ] **Step 1: Add runtime import and helper**

Import `WooPaymentsLegacyRuntime` and add a private static `is_woopayments_loaded(): bool` helper with the same container-backed fail-closed behavior as `WCPayPromotion\Init`.

- [ ] **Step 2: Replace direct note active check**

Update `is_installed()` so the loaded-runtime check goes through the helper, while the existing `validate_plugin( self::PLUGIN_FILE )` installation check stays unchanged.

### Task 5: Verification, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3o-woopayments-promotion-runtime`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WcPayWelcomePageTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\WCPayPromotion\\\\InitTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\Notes\\\\WooCommercePaymentsTest'`

Expected: PASS.

- [ ] **Step 2: Run broader promotion/admin note regression tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WcPayWelcomePageTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\WCPayPromotion\\\\InitTest|DefaultPromotionsTest|Automattic\\\\WooCommerce\\\\Tests\\\\Internal\\\\Admin\\\\Notes\\\\WooCommercePaymentsTest'`

Expected: PASS.

- [ ] **Step 3: Run static gates**

Run syntax checks for touched production/test files, PHPStan for touched production files, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, `git diff --cached --check`, and post-commit `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:branch`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 4: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then append B3o evidence above the implementation-log append marker and staging-log append marker. Include RED failures, focused and broader GREEN results, static gates, commit hash, and git range.
