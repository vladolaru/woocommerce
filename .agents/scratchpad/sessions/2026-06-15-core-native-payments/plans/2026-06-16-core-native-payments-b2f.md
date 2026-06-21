# Core Native Payments B2f Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mirror WooPayments request-context gating for native multi-currency live hook registration.

**Architecture:** Add a small native request-context helper for frontend, Store API, Store API batch, and admin REST detection. Inject it into the live multi-currency controllers via test setters and lazy defaults, then gate only the same hook groups WooPayments gates.

**Tech Stack:** WooCommerce core PHP, WordPress/WooCommerce request helpers, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments parity rules, read-only:

- `FrontendPrices::init_hooks()` registers no price hooks for cron, admin, or admin REST.
- `FrontendCurrencies::init_hooks()` gates the main currency and order-total hooks for cron/admin/admin REST, but still registers thank-you, account order, cart-hash, and shipping hooks.
- `MultiCurrency::init_hooks()` registers selected-currency URL/geolocation/new-customer writers only for frontend or Store API requests.
- `Utils::is_admin_api_request()` treats admin-referred non-Store REST requests as admin API requests.
- `Utils::is_store_api_request()` uses `WC()->is_store_api_request()` when available, with a request URI fallback.
- `Utils::is_store_batch_request()` identifies `/wc/store/.../batch` routes.

B2f deliberately does not implement order-currency callback behavior, compatibility class loading, switcher UI, Storefront placement, REST/settings, async rendering, or shipping `price_decimals` parity.

## File Structure

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContext.php`
    - Detects frontend requests, Store API requests, Store API batch requests, and admin REST requests.
    - Provides named predicates for frontend price/currency hook registration and selected-currency writer registration.
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContextTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
    - Gate the full price hook group behind frontend-context parity.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php`
    - Assert price hooks do not register in blocked request contexts.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
    - Gate only the main currency/order-total hooks behind frontend-context parity.
    - Keep thank-you, account order, cart-hash, and shipping hooks registered when core owns MC.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
    - Assert the split hook registration behavior.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
    - Gate URL and new-customer writer hooks to frontend or Store API contexts.
    - Keep My Account render/save hooks registered when core owns MC.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`
    - Assert selected-currency writer registration is context-gated.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2f-multi-currency-request-context-gates`

## Tasks

### Task 1: Request Context Helper

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContext.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContextTest.php`

- [ ] **Step 1: Write failing request-context tests**

Add tests for these behaviors:

```php
public function test_treats_normal_request_as_frontend_request(): void
public function test_blocks_frontend_hooks_for_admin_context(): void
public function test_allows_selected_currency_writers_for_store_api_context(): void
public function test_detects_store_api_batch_routes(): void
```

- [ ] **Step 2: Run the red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRequestContextTest
```

Expected: fail because `MultiCurrencyRequestContext` does not exist.

- [ ] **Step 3: Implement the helper**

Implement:

```php
public function is_frontend_request(): bool
public function is_store_api_request(): bool
public function is_store_batch_request(): bool
public function is_admin_api_request(): bool
public function should_register_frontend_hooks(): bool
public function should_register_selected_currency_entry_hooks(): bool
```

- [ ] **Step 4: Run the green helper tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRequestContextTest
```

Expected: pass.

### Task 2: Frontend Controller Gates

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`

- [ ] **Step 1: Write failing controller-gate tests**

Add tests showing:

```php
public function test_does_not_register_frontend_price_hooks_in_blocked_request_context(): void
public function test_registers_only_always_on_currency_hooks_in_blocked_request_context(): void
```

- [ ] **Step 2: Run red controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest'
```

Expected: fail because blocked request contexts still register all hooks.

- [ ] **Step 3: Apply request-context gates**

Use lazy default `MultiCurrencyRequestContext` instances and test setters. Do not change callback behavior in this slice.

- [ ] **Step 4: Run green controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest'
```

Expected: pass.

### Task 3: Selected-Currency Controller Gates

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`

- [ ] **Step 1: Write failing selected-currency gate tests**

Add tests showing URL/new-customer writer hooks are skipped in blocked contexts while My Account hooks still register:

```php
public function test_registers_only_account_hooks_in_blocked_request_context(): void
public function test_registers_writer_hooks_for_store_api_context(): void
```

- [ ] **Step 2: Run red selected-currency controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyControllerTest
```

Expected: fail because writer hooks still register in blocked contexts.

- [ ] **Step 3: Gate writer hooks**

Register `init` and `woocommerce_created_customer` only when `should_register_selected_currency_entry_hooks()` is true.

- [ ] **Step 4: Run green selected-currency tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyControllerTest
```

Expected: pass.

### Task 4: Verification, Changelog, Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2f-multi-currency-request-context-gates`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use a patch-style changelog file consistent with B2 slices.

- [ ] **Step 2: Run regression and static checks**

Run focused regression including B2e:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRequestContextTest|MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run static checks:

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2f.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2f code/changelog**

Do not stage `.agents/`, `docs/superpowers/`, or external staging log.

- [ ] **Step 4: Run staged lint and commit**

Run:

```bash
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
git commit
```

Commit subject:

```text
fix(payments): gate native payments multi-currency hooks by request context
```

## Self-Review

- Spec coverage: B2f covers request-context registration parity only.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: helper/controller method names are consistent across tasks.
