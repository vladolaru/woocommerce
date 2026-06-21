# Native Payments B1i Multi-Currency Tracker Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a non-mutating native projection for WooPayments-compatible multi-currency tracker payload data.

**Architecture:** Build tracker payloads from `MultiCurrencyStateBuilder` without registering `woocommerce_tracker_data` or executing reporting SQL. Order counts are accepted as an already-computed input so this slice can preserve the payload shape while deferring HPOS/postmeta aggregate queries.

**Tech Stack:** PHP 8.1, WooCommerce internal services, PHPUnit, WordPress option APIs.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionService.php`
    - Responsibility: project the `wcpay_multi_currency` tracker payload only.
    - Reads rate-type options with `wp_prime_option_caches()` before the loop.
    - No hooks, SQL queries, option writes, or WPCOM/WooPayments source edits.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionServiceTest.php`
    - Responsibility: verify payload shape, default/non-default currency fields, option-derived rate labels, and supplied order-count passthrough.
- Create `plugins/woocommerce/changelog/add-native-payments-b1i-multi-currency-tracker-projection`
    - WooCommerce changelog entry for the B1i tracker projection slice.

## Task 1: Write The Failing Tracker Projection Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionServiceTest.php`

- [ ] **Step 1: Add tests for WooPayments-compatible tracker payloads**

Test methods to add:

```php
public function test_projects_tracker_data_with_enabled_non_default_currencies(): void;
public function test_marks_default_rate_rounding_and_charm_values(): void;
public function test_passes_supplied_order_counts_without_querying(): void;
```

Assertions:

- payload key is `wcpay_multi_currency`;
- `default_currency` contains only `code` and decoded `name`;
- `enabled_currencies` excludes the default currency;
- automatic rate type becomes `automatic (default)`;
- manual rate type remains `manual`;
- rounding default values append ` (default)`;
- charm `0.00` becomes `0.00 (default)`;
- supplied `order_counts` array is copied unchanged.

- [ ] **Step 2: Run the focused test and verify it fails**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyTrackingProjectionServiceTest
```

Expected: FAIL because `MultiCurrencyTrackingProjectionService` is missing.

## Task 2: Implement The Tracker Projection Service

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionService.php`

- [ ] **Step 1: Add `MultiCurrencyTrackingProjectionService`**

Required public API:

```php
public const TRACKER_KEY = 'wcpay_multi_currency';

public function __construct( MultiCurrencyStateBuilder $state_builder );

/**
 * @param array<string,mixed> $data Existing tracker data.
 * @param array<string,mixed> $order_counts Precomputed order-count payload.
 * @return array<string,mixed>
 */
public function project_tracker_data( array $data, array $order_counts = array() ): array;
```

Implementation notes:

- Build state once with `$this->state_builder->build()`.
- Default `order_counts` to `array( 'counts' => 0, 'currencies' => array() )`.
- Exclude default currency from `enabled_currencies`.
- For non-default currencies, read rate type from
  `wcpay_multi_currency_exchange_rate_{$currency->get_id()}` with default
  `automatic`.
- Prime those option keys before reading them:

```php
if ( array() !== $rate_option_keys ) {
	// Prime caches to reduce future queries.
	wp_prime_option_caches( $rate_option_keys );
}
```

- Currency data shape:

```php
array(
	'code' => 'GBP',
	'name' => 'Pound sterling',
)
```

- Non-default additional data:

```php
array(
	'is_zero_decimal' => false,
	'rate_type'       => 'automatic (default)',
	'price_rounding'  => '1.00 (default)',
	'price_charm'     => '0.00 (default)',
)
```

- [ ] **Step 2: Run the focused test and verify it passes**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyTrackingProjectionServiceTest
```

Expected: PASS.

## Task 3: Add Changelog And Regression Coverage

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b1i-multi-currency-tracker-projection`

- [ ] **Step 1: Add changelog entry**

```text
Significance: minor
Type: add
Comment: Add native multi-currency tracker payload projections.
```

- [ ] **Step 2: Run the B0-B1i regression set**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

## Task 4: Static Checks And Commit

**Files:**

- Stage only:
    - `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionService.php`
    - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionServiceTest.php`
    - `plugins/woocommerce/changelog/add-native-payments-b1i-multi-currency-tracker-projection`

- [ ] **Step 1: Run static checks**

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Expected: PASS.

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
```

Expected: PASS.

- [ ] **Step 2: Run staged checks**

```bash
git diff --check
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1i.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1i-multi-currency-tracker-projection
git commit -m "feat(payments): add native payments B1i multi-currency tracker projection"
```

## Self-Review

- Spec coverage: B1i covers the WooPayments tracker data shape for enabled/default currency data and order-count payload placement.
- Explicit gaps: hook registration and direct HPOS/postmeta order-count SQL remain later slices.
- Performance review: derived rate-type option keys are primed before `get_option()` calls in the enabled-currency loop.
