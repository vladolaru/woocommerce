# Native Payments B1g Multi-Currency Analytics Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add read-only native multi-currency analytics projections for order stats and customer-currency analytics inputs.

**Architecture:** Keep B1g as a projection service under `src/Internal/MultiCurrency/Services/`. It consumes B1c state snapshots and existing order meta constants, returns WooPayments-compatible analytics data shapes, and leaves SQL clause rewriting plus hook registration to a later slice.

**Tech Stack:** WooCommerce Core PHP, WC CRUD for order meta reads, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

## File Map

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAnalyticsProjectionService.php`
    - Read-only analytics projection for order stats, request args, and customer-currency options.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAnalyticsProjectionServiceTest.php`
    - Unit coverage for WooPayments-compatible analytics transformations.
- Create: `plugins/woocommerce/changelog/add-native-payments-b1g-multi-currency-analytics-projection`
    - WooCommerce changelog entry for the analytics projection slice.

## Task 1: Order Stats Conversion Projection

- [ ] **Step 1: Write failing tests**

Add `MultiCurrencyAnalyticsProjectionServiceTest` with tests for:

```php
public function test_converts_order_stats_to_default_currency(): void;
public function test_prefers_stripe_exchange_rate_for_order_stats(): void;
public function test_leaves_default_currency_order_stats_unchanged(): void;
public function test_leaves_order_stats_unchanged_when_required_meta_is_missing(): void;
```

Use real `WC_Order` objects and meta keys already preserved by `MultiCurrencyPriceProjectionService`:
`_wcpay_multi_currency_order_exchange_rate`,
`_wcpay_multi_currency_order_default_currency`, and
`_wcpay_multi_currency_stripe_exchange_rate`.

- [ ] **Step 2: Verify red**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAnalyticsProjectionServiceTest
```

Expected: fail because `MultiCurrencyAnalyticsProjectionService` does not exist.

- [ ] **Step 3: Implement minimal order-stats projection**

Create `MultiCurrencyAnalyticsProjectionService::update_order_stats_data( array $args, \WC_Order $order ): array`.

The method must:

- Return original args when order currency is the default currency.
- Return original args when required multi-currency meta is missing or the stored default-currency meta does not match the native state default.
- Use Stripe exchange rate meta when present.
- Otherwise use `1 / _wcpay_multi_currency_order_exchange_rate`.
- Round `net_total`, `shipping_total`, and `tax_total` to `wc_get_price_decimals()`.
- Recalculate `total_sales` as converted `net_total + shipping_total + tax_total`.

- [ ] **Step 4: Verify green**

Run the focused test command and expect PASS.

## Task 2: Customer Currency Analytics Inputs

- [ ] **Step 1: Add failing request/customer-currency tests**

Add tests for:

```php
public function test_applies_customer_currency_request_args(): void;
public function test_projects_customer_currency_options_with_default_currency(): void;
public function test_skips_customer_currency_options_not_available_in_state(): void;
```

Use plain arrays instead of `$_GET` so B1g remains deterministic and read-only.

- [ ] **Step 2: Verify red**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAnalyticsProjectionServiceTest
```

Expected: fail on missing methods.

- [ ] **Step 3: Implement request and selector projections**

Add:

```php
public function apply_customer_currency_args( array $args, array $request_args ): array;
public function get_customer_currency_options(): array;
```

`apply_customer_currency_args()` must sanitize `currency_is`, `currency_is_not`, and `currency` values and merge them into the supplied args with the same keys WooPayments uses. `get_customer_currency_options()` must combine stored customer currencies from `MultiCurrencyState::get_customer_currencies()` with the default currency, skip currencies absent from available state, and return `label`/`value` arrays.

- [ ] **Step 4: Verify green**

Run the focused test command and expect PASS.

## Task 3: Verification And Commit

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: minor
Type: add
Comment: Add read-only native multi-currency analytics projections.
```

- [ ] **Step 2: Run B0-B1g regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

- [ ] **Step 3: Run static checks**

Run:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1g.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

Expected: all checks pass.

- [ ] **Step 4: Commit**

Stage only WooCommerce production, test, and changelog files. Do not stage `.agents/*` or `docs/superpowers/*`.

Commit:

```bash
git commit -m "feat(payments): add native payments B1g multi-currency analytics projection"
```
