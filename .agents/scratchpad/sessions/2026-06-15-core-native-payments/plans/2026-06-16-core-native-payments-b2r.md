# Core Native Payments B2r Tracking Controller Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register native multi-currency WooCommerce tracker data when core owns multi-currency.

**Architecture:** Add a thin `MultiCurrencyTrackingController` that gates the preserved `woocommerce_tracker_data` filter behind `MultiCurrencyRuntimeArbiter::should_core_register()`. The controller delegates payload shape to `MultiCurrencyTrackingProjectionService` and executes the existing `MultiCurrencyTrackingOrderCountProjectionService` SQL for HPOS or legacy order storage.

**Tech Stack:** WooCommerce core PHP, WordPress filters, WooCommerce order storage utilities, PHPUnit through `pnpm test:php:env`, PHPCS/PHPStan.

---

## Files

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyTrackingController.php`
    - Runtime-gated registration for `woocommerce_tracker_data` at priority 50.
    - Callback executes the order-count query and delegates final payload shape to the projection service.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyTrackingControllerTest.php`
    - TDD coverage for runtime registration, hook idempotence, payload projection, and order-count SQL execution.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the tracking controller from WooCommerce bootstrap beside other native multi-currency controllers.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2r-multi-currency-tracking`
    - Record the tracking hook parity fix.

## Task 1: Write Failing Controller Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyTrackingControllerTest.php`

- [ ] **Step 1: Create the test class**

Use `WC_Unit_Test_Case`, clear `woocommerce_tracker_data` filters in teardown, and create a static `MultiCurrencyRuntimeArbiter` test double whose `should_core_register()` returns true only for `MultiCurrencyRuntimeArbiter::OWNER_CORE`.

- [ ] **Step 2: Add registration tests**

Assert that plugin-owned runtime registers nothing:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

$sut->register();

$this->assertFalse( has_filter( 'woocommerce_tracker_data', array( $sut, 'add_tracker_data' ) ) );
```

Assert that core-owned runtime registers the preserved filter once at priority 50:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

$sut->register();
$sut->register();

$this->assertSame( 50, has_filter( 'woocommerce_tracker_data', array( $sut, 'add_tracker_data' ) ) );
```

- [ ] **Step 3: Add callback behavior tests**

Inject a projection service test double that records the `$data` and `$order_counts` arguments and returns:

```php
array(
	'existing'             => 'value',
	'wcpay_multi_currency' => $order_counts,
)
```

Inject an order-count service test double whose query is `SELECT 'GBP' AS currency, 'woocommerce_payments' AS gateway, 2 AS counts, 20.5 AS totals`. Assert that `add_tracker_data( array( 'existing' => 'value' ) )` preserves existing data and supplies the aggregated WooPayments-compatible order counts.

- [ ] **Step 4: Add storage-mode coverage**

Inject an order-count service test double that records the `$is_hpos_enabled` argument passed to `get_order_count_query()`. Set the controller storage-mode resolver to return `true` and assert the service receives `true`; set it to return `false` and assert the service receives `false`.

- [ ] **Step 5: Run the focused test to verify RED**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyTrackingControllerTest
```

Expected: fail because `MultiCurrencyTrackingController` does not exist yet.

## Task 2: Implement The Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyTrackingController.php`

- [ ] **Step 1: Add controller skeleton**

Implement `RegisterHooksInterface`, inject `MultiCurrencyRuntimeArbiter` through `init()`, and add internal setters for `MultiCurrencyTrackingProjectionService`, `MultiCurrencyTrackingOrderCountProjectionService`, and a storage-mode resolver callable used by tests.

- [ ] **Step 2: Register the preserved filter**

Register the WooPayments-compatible tracker hook only when core owns multi-currency:

```php
if ( ! $this->arbiter->should_core_register() ) {
	return;
}

$this->add_filter_once( 'woocommerce_tracker_data', array( $this, 'add_tracker_data' ), 50 );
```

- [ ] **Step 3: Implement `add_tracker_data()`**

Query order-count rows through `$wpdb->get_results( $query )`, aggregate them with `MultiCurrencyTrackingOrderCountProjectionService::aggregate_order_count_rows()`, and call `MultiCurrencyTrackingProjectionService::project_tracker_data( $data, $order_counts )`.

- [ ] **Step 4: Implement default dependencies**

Create the default projection service from `MultiCurrencyStateBuilder`, `MultiCurrencyLocalizationService`, `MultiCurrencyRateService`, `CurrencyRateProviderRegistry`, and `MultiCurrencyDatabaseCache`. Resolve HPOS mode through `Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()` when the class exists, otherwise false.

- [ ] **Step 5: Run the focused test to verify GREEN**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyTrackingControllerTest
```

Expected: pass.

## Task 3: Bootstrap, Changelog, And Verification

**Files:**

- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Create: `plugins/woocommerce/changelog/add-native-payments-b2r-multi-currency-tracking`

- [ ] **Step 1: Register the controller in WooCommerce bootstrap**

Add the controller beside the other native multi-currency controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyTrackingController::class )->register();
```

- [ ] **Step 2: Add the changelog entry**

Use this entry:

```text
Significance: patch
Type: fix
Comment: Add native multi-currency tracker data hook registration.
```

- [ ] **Step 3: Run focused and regression checks**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyTrackingControllerTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyTrackingController.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyTrackingController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyTrackingControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2r.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 4: Commit only source, test, bootstrap, and changelog files**

Do not stage `docs/superpowers/`, `.agents/`, generated assets, or the external staging log.

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyTrackingController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyTrackingControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2r-multi-currency-tracking
git commit -m "fix(payments): add multi-currency tracking hook"
```
