# Native Payments B1c Multi-Currency Shadow State Implementation Plan

<!-- markdownlint-disable MD007 MD032 -->

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a non-mutating native multi-currency state builder that prepares default, enabled, and selected currency objects for later shadow-price comparison.

**Architecture:** `MultiCurrencyStateBuilder` reads WooCommerce currency settings plus preserved `wcpay_multi_currency_*` options and the stored `wcpay_currency` user/session value, then returns a `MultiCurrencyState` value object. It does not initialize sessions, update options, register hooks, recalculate carts, write user meta, write order meta, or render frontend output.

**Tech Stack:** WooCommerce Core PHP, WordPress options/user/session access, PHPUnit, PHPStan, PHPCS.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyState.php`
  - Value object containing available currencies, enabled currencies, default currency, and selected currency.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php`
  - Builds state from WooCommerce currency data, preserved options, `MultiCurrencyRateService`, and selected user/session currency.
- Create tests:
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStateTest.php`
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php`
- Create changelog:
  - `plugins/woocommerce/changelog/add-native-payments-b1c-multi-currency-state`

## Task 1: State Value Object

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyState.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyStateTest.php`

- [ ] **Step 1: Write failing tests**

Assert:
- constructor stores available, enabled, default, and selected currencies;
- `has_additional_currencies_enabled()` returns true only when enabled has more than the default currency;
- getters preserve currency arrays keyed by uppercase code.

- [ ] **Step 2: Run red tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStateTest'
```

Expected: FAIL because `MultiCurrencyState` does not exist.

- [ ] **Step 3: Implement state object**

Expose:

```php
public function get_available_currencies(): array
public function get_enabled_currencies(): array
public function get_default_currency(): MultiCurrencyCurrency
public function get_selected_currency(): MultiCurrencyCurrency
public function has_additional_currencies_enabled(): bool
```

- [ ] **Step 4: Run green tests**

Run the same focused filter. Expected: PASS.

## Task 2: Non-Mutating State Builder

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php`

- [ ] **Step 1: Write failing tests**

Assert:
- no enabled option produces default-currency-only state;
- `wcpay_multi_currency_enabled_currencies` plus manual rate options build non-default enabled currencies without a provider;
- automatic-rate currencies are skipped when `MultiCurrencyRateService` returns `null`;
- rounding defaults to `100` for zero-decimal currencies and `1.00` otherwise;
- stored `wcpay_currency` user meta selects an enabled currency;
- missing/disabled selected currency falls back to default.

- [ ] **Step 2: Run red tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStateBuilderTest'
```

Expected: FAIL because `MultiCurrencyStateBuilder` does not exist.

- [ ] **Step 3: Implement state builder**

Constructor:

```php
public function __construct(
	MultiCurrencyLocalizationInterface $localization_service,
	MultiCurrencyRateService $rate_service
) {}
```

Read these preserved keys:
- `wcpay_multi_currency_enabled_currencies`
- `wcpay_multi_currency_price_rounding_{currency}`
- `wcpay_multi_currency_price_charm_{currency}`
- `wcpay_currency` from current user meta, or from existing `WC()->session` only if already present.

Do not call `WC()->initialize_session()`.

- [ ] **Step 4: Run green tests**

Run the same focused filter. Expected: PASS.

## Task 3: Verification And Commit

- [ ] **Step 1: Run B1c regression**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

- [ ] **Step 2: Run static checks**

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse src/Internal/MultiCurrency --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

If directory PHPStan hits the known baseline-pattern artifact, rerun with explicit file paths and record the artifact.

- [ ] **Step 3: Run repository checks**

```bash
git diff --check
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-15-core-native-payments-b1c.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add plugins/woocommerce/changelog/add-native-payments-b1c-multi-currency-state plugins/woocommerce/src/Internal/MultiCurrency plugins/woocommerce/tests/php/src/Internal/MultiCurrency
git commit -m "feat(payments): add native payments B1c multi-currency state"
```

## Self-Review

- Spec coverage: This prepares shadow state for B1 without mutating the pricing pipeline. It does not implement frontend filters, geolocation switching, REST routes, analytics, or order meta writing.
- Placeholder scan: No `TBD`, `TODO`, or unspecified "write tests" steps remain.
- Type consistency: State builder consumes B1a/B1b services and returns `MultiCurrencyCurrency` objects inside `MultiCurrencyState`.
