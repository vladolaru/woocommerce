# Native Payments B1b Multi-Currency Conversion Engine Implementation Plan

<!-- markdownlint-disable MD007 MD032 -->

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the non-filtering native multi-currency conversion engine foundation: currency value objects and conversion math.

**Architecture:** Keep this slice pure and non-mutating. `MultiCurrency` gets a core-native currency model and calculator that can compute converted prices from supplied currency state, but it does not read sessions, register WooCommerce filters, write order meta, or alter frontend output. Later B1 shadow work can feed real request/store state into this calculator and compare against plugin output.

**Tech Stack:** WooCommerce Core PHP, PHPUnit, PHPStan, PHPCS.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Exceptions/InvalidCurrencyException.php`
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Exceptions/InvalidCurrencyRateException.php`
- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCurrency.php`
  - Native value object equivalent to WooPayments `Currency`.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceCalculator.php`
  - Pure conversion logic equivalent to WooPayments `get_price()`, `get_adjusted_price()`, `ceil_price()`, and `get_raw_conversion()`.
- Create tests:
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyCurrencyTest.php`
  - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceCalculatorTest.php`
- Create changelog:
  - `plugins/woocommerce/changelog/add-native-payments-b1b-multi-currency-conversion`

## Task 1: Currency Value Object

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCurrency.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyCurrencyTest.php`

- [ ] **Step 1: Write failing tests**

Assert:
- code is normalized to uppercase and ID is lowercase;
- default currency is detected from the constructor flag rather than global runtime state;
- zero-decimal detection uses `MultiCurrencyLocalizationInterface`;
- default charm is `0.0`, default rounding is `'0'`, and `set_charm()`, `set_rounding()`, `set_rate()`, `set_last_updated()` update returned values;
- `jsonSerialize()` returns `id`, `code`, `name`, `rate`, `symbol`, `symbol_position`, `is_zero_decimal`, `is_default`, `charm`, `rounding`, and `last_updated`.

- [ ] **Step 2: Run red tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyCurrencyTest'
```

Expected: FAIL because `MultiCurrencyCurrency` does not exist.

- [ ] **Step 3: Implement currency object**

Use injected localization and constructor parameters:

```php
public function __construct(
	MultiCurrencyLocalizationInterface $localization_service,
	string $code,
	float $rate = 1.0,
	bool $is_default = false,
	?int $last_updated = null
)
```

Avoid direct session/global ownership decisions. Use WooCommerce core helpers only for currency names and symbols.

- [ ] **Step 4: Run green tests**

Run the same focused filter. Expected: PASS.

## Task 2: Pure Price Calculator

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Exceptions/InvalidCurrencyException.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Exceptions/InvalidCurrencyRateException.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceCalculator.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceCalculatorTest.php`

- [ ] **Step 1: Write failing tests**

Assert:
- unsupported price type returns the original amount as float;
- default currency returns the original amount as float;
- product conversion applies rate, rounding, and charm;
- shipping conversion applies charm only when configured to do so;
- coupon/tax/exchange-rate conversion rounds to the target currency decimals and does not apply charm;
- raw conversion uses `amount * ( to_rate / from_rate )`;
- raw conversion throws `InvalidCurrencyException` when either code is missing;
- raw conversion throws `InvalidCurrencyRateException` when the source rate is not positive.

- [ ] **Step 2: Run red tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPriceCalculatorTest'
```

Expected: FAIL because calculator and exception classes do not exist.

- [ ] **Step 3: Implement calculator**

Constructor:

```php
public function __construct( MultiCurrencyLocalizationInterface $localization_service ) {}
```

Methods:

```php
public function get_price( $price, string $type, MultiCurrencyCurrency $currency, bool $apply_charm_only_to_products = true ): float
public function get_raw_conversion( float $amount, string $to_currency, string $from_currency, array $enabled_currencies ): float
```

`$enabled_currencies` is an array keyed by uppercase currency code with `MultiCurrencyCurrency` values.

- [ ] **Step 4: Run green tests**

Run the same focused filter. Expected: PASS.

## Task 3: Verification And Commit

- [ ] **Step 1: Run B1b regression**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
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
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-15-core-native-payments-b1b.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add plugins/woocommerce/changelog/add-native-payments-b1b-multi-currency-conversion plugins/woocommerce/src/Internal/MultiCurrency plugins/woocommerce/tests/php/src/Internal/MultiCurrency
git commit -m "feat(payments): add native payments B1b multi-currency conversion"
```

## Self-Review

- Spec coverage: This covers B1 conversion math foundation only. It does not wire request state, sessions, frontend hooks, REST routes, switchers, or analytics.
- Placeholder scan: No `TBD`, `TODO`, or unspecified "write tests" steps remain.
- Type consistency: Calculator methods consume `MultiCurrencyCurrency` and the B1a localization service interface.
