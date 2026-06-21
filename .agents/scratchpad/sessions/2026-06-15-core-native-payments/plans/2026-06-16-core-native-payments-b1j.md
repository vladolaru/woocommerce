# Native Payments B1j Multi-Currency Tracker Order Count Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a non-mutating native projection for WooPayments-compatible multi-currency tracker order-count SQL and aggregation.

**Architecture:** Build the HPOS and legacy SQL strings from WordPress table names, but do not execute them. Aggregate already-supplied result rows into the same `order_counts` payload shape used by WooPayments tracking.

**Tech Stack:** PHP 8.1, WooCommerce internal services, PHPUnit, WordPress `$wpdb` table naming.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionService.php`
    - Responsibility: build order-count SQL strings and aggregate supplied rows.
    - No `$wpdb->get_results()`, hooks, writes, or WPCOM/WooPayments source edits.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionServiceTest.php`
    - Responsibility: verify HPOS SQL, legacy SQL, and aggregation output.
- Create `plugins/woocommerce/changelog/add-native-payments-b1j-multi-currency-tracker-order-count-projection`
    - WooCommerce changelog entry for the B1j tracker order-count projection slice.

## Task 1: Write The Failing Order-Count Projection Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionServiceTest.php`

- [ ] **Step 1: Add tests**

Test methods to add:

```php
public function test_builds_hpos_order_count_query(): void;
public function test_builds_legacy_order_count_query(): void;
public function test_aggregates_order_count_rows(): void;
public function test_aggregates_missing_gateway_as_unknown(): void;
```

Assertions:

- HPOS query reads `wc_orders`, `wc_orders_meta`, `orders.total_amount`,
  `orders.currency`, `orders.payment_method`, and the preserved
  `_wcpay_multi_currency_order_exchange_rate` meta key.
- Legacy query reads `posts`, `postmeta`, `_payment_method`, `_order_total`,
  `_order_currency`, and the preserved exchange-rate meta key.
- Aggregation returns:

```php
array(
	'counts'     => 3,
	'currencies' => array(
		'GBP' => array(
			'counts'   => 2,
			'totals'   => 20.5,
			'gateways' => array(
				'woocommerce_payments' => array(
					'counts' => 2,
					'totals' => 20.5,
				),
			),
		),
	),
)
```

- Missing gateway values use `unknown`.

- [ ] **Step 2: Run the focused test and verify it fails**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyTrackingOrderCountProjectionServiceTest
```

Expected: FAIL because `MultiCurrencyTrackingOrderCountProjectionService` is missing.

## Task 2: Implement The Projection Service

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionService.php`

- [ ] **Step 1: Add `MultiCurrencyTrackingOrderCountProjectionService`**

Required public API:

```php
public function get_order_count_query( bool $is_hpos_enabled ): string;

/**
 * @param array<int,array<string,mixed>|object> $rows Query result rows.
 * @return array<string,mixed>
 */
public function aggregate_order_count_rows( array $rows ): array;
```

Implementation notes:

- Use `$wpdb->prefix` and `$wpdb->postmeta` for table names.
- Return SQL strings only; do not call `$wpdb->get_results()`.
- Preserve WooPayments status set:
  `wc-completed`, `wc-processing`, `wc-refunded`.
- Aggregate `counts` as integers and `totals` as floats.
- Preserve gateway values by currency; default missing gateway to `unknown`.

- [ ] **Step 2: Run the focused test and verify it passes**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyTrackingOrderCountProjectionServiceTest
```

Expected: PASS.

## Task 3: Add Changelog And Regression Coverage

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b1j-multi-currency-tracker-order-count-projection`

- [ ] **Step 1: Add changelog entry**

```text
Significance: minor
Type: add
Comment: Add native multi-currency tracker order-count projections.
```

- [ ] **Step 2: Run the B0-B1j regression set**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

## Task 4: Static Checks And Commit

**Files:**

- Stage only:
    - `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionService.php`
    - `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionServiceTest.php`
    - `plugins/woocommerce/changelog/add-native-payments-b1j-multi-currency-tracker-order-count-projection`

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
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1j.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyTrackingOrderCountProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1j-multi-currency-tracker-order-count-projection
git commit -m "feat(payments): add native payments B1j multi-currency tracker order counts"
```

## Self-Review

- Spec coverage: B1j covers the query strings and row aggregation needed to feed B1i order counts.
- Explicit gaps: no database execution, tracker hook registration, or runtime ownership registration.
- Performance review: this slice does not run SQL. The SQL shape matches WooPayments' grouped aggregate queries and avoids N-query loops.
