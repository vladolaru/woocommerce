---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 12:17
status: draft
---

# Core Native Payments B3l Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the WooPayments concrete account adapter from the generic multi-currency settings controller while preserving the autowired container bootstrap path.

**Architecture:** `MultiCurrencySettingsController` already treats the provider account as a generic account boundary at runtime, but its `init()` signature still hard-codes `WooPaymentsLegacyAccountAdapter`, which leaks the current provider into the settings controller and its tests. B3l adds a provider-neutral `MultiCurrencyProviderAccountResolver` in the generic multi-currency provider layer, lets that resolver choose the current WooPayments legacy account adapter, and rewires the settings controller to depend on the resolver. This keeps the controller provider-neutral without type-hinting `MultiCurrencyAccountInterface` directly, because the WooCommerce container resolves concrete classes by reflection and cannot instantiate an interface.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/MultiCurrency`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Settings Account Boundary Tests

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/MultiCurrencyProviderAccountResolverTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php`

- [ ] **Step 1: Add resolver behavior coverage**

Create `MultiCurrencyProviderAccountResolverTest` with coverage that the resolver is container-resolvable, delegates provider connection state and onboarding URL to a configured `MultiCurrencyAccountInterface`, and returns defensive defaults when no provider account can answer.

- [ ] **Step 2: Update controller expectations first**

Update `MultiCurrencySettingsControllerTest` to construct the controller with a provider-neutral resolver and fake `MultiCurrencyAccountInterface` implementations instead of anonymous subclasses of `WooPaymentsLegacyAccountAdapter`. Keep tests that prove connected settings pages and onboarding CTAs can still come from the production account path.

- [ ] **Step 3: Add source-boundary regression coverage**

Extend `MultiCurrencyDomainMapTest` with a settings boundary assertion that `MultiCurrencySettingsController.php` no longer imports or mentions `WooPaymentsLegacyAccountAdapter` or the `Internal\Payments\Providers\WooPayments` namespace, and that the controller depends on `MultiCurrencyProviderAccountResolver`.

- [ ] **Step 4: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProviderAccountResolverTest|MultiCurrencySettingsControllerTest|MultiCurrencyDomainMapTest'`

Expected: FAIL because `MultiCurrencyProviderAccountResolver` does not exist and `MultiCurrencySettingsController` still type-hints `WooPaymentsLegacyAccountAdapter`.

### Task 2: Provider Account Resolver

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/MultiCurrencyProviderAccountResolver.php`

- [ ] **Step 1: Add the resolver**

Create `MultiCurrencyProviderAccountResolver` with `init( WooPaymentsLegacyAccountAdapter $woo_payments_account_adapter )`, `set_account( MultiCurrencyAccountInterface $account )`, `is_provider_connected()`, and `get_provider_onboarding_page_url()`. Store the active account as `?MultiCurrencyAccountInterface` so tests can configure generic account fakes and runtime bootstrap can inject the WooPayments adapter.

- [ ] **Step 2: Make provider calls defensive**

Return `false` from `is_provider_connected()` and `''` from `get_provider_onboarding_page_url()` when no account is available or when the active account throws. Do not add settings-page fallback URLs to the resolver; keep fallback URL ownership in the settings controller.

### Task 3: Settings Controller Rewire

**Files:**
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php`

- [ ] **Step 1: Inject the provider-neutral resolver**

Replace the `WooPaymentsLegacyAccountAdapter` import, property, docblock, and `init()` parameter with `MultiCurrencyProviderAccountResolver`.

- [ ] **Step 2: Delegate account reads through the resolver**

Update `is_provider_connected()` and `get_onboarding_url()` to call the resolver instead of calling a provider adapter directly. Preserve the controller-level resolver setter seams, onboarding fallback URL, exception handling, hook registrations, asset behavior, and settings projection behavior.

### Task 4: Verification, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3l-mc-settings-account-resolver`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProviderAccountResolverTest|MultiCurrencySettingsControllerTest|MultiCurrencyDomainMapTest'`

Expected: PASS.

- [ ] **Step 2: Run static gates**

Run syntax checks for the new resolver and touched settings controller, PHPStan for touched production files, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, and `git diff --cached --check`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 3: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then append B3l evidence above the implementation-log append marker and staging-log append marker. Include the RED failure, focused GREEN result, static gates, commit hash, and git range.
