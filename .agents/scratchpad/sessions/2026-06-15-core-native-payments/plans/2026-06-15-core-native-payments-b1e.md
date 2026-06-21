# Native Payments B1e Multi-Currency Shadow Comparison Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add disabled-by-default, read-only multi-currency shadow comparison for order/refund meta surfaces.

**Architecture:** Follow the existing native payments shadow pattern, but keep multi-currency as its own domain. Add compact comparison records, a local surface differ, and a `MultiCurrencyShadowMode` observer that only hooks when explicitly enabled and the WooPayments plugin owns multi-currency; the observer compares plugin-actual persisted meta with B1d native candidates and logs out of band.

**Tech Stack:** WooCommerce Core PHP 8.1-compatible code, `RegisterHooksInterface`, `LegacyProxy`, `WC_Unit_Test_Case`, `MultiCurrencyRuntimeArbiter`, and B1d `MultiCurrencyPriceProjectionService`.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencySurfaceDiffer.php`
    - Responsibility: diff nested arrays using stable dot paths without depending on the payments namespace.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowComparison.php`
    - Responsibility: immutable comparison record and compact log payload for multi-currency shadow results.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php`
    - Responsibility: disabled-by-default observer hook registration, actual-surface reading, native-candidate comparison, and WC logger output.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow/MultiCurrencySurfaceDifferTest.php`
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowComparisonTest.php`
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowModeTest.php`
- Modify `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the shadow mode alongside the payments shadow mode.
- Create `plugins/woocommerce/changelog/add-native-payments-b1e-multi-currency-shadow`

## Task 1: Surface Differ

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow/MultiCurrencySurfaceDifferTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencySurfaceDiffer.php`

- [x] **Step 1: Write failing differ tests**

Add tests equivalent to the payments differ behavior:

```php
/**
 * @testdox Should return no differences for identical surfaces.
 */
public function test_returns_no_differences_for_identical_surfaces(): void {
	$sut = new MultiCurrencySurfaceDiffer();

	$this->assertSame(
		array(),
		$sut->diff(
			array( 'meta' => array( 'rate' => 0.82 ) ),
			array( 'meta' => array( 'rate' => 0.82 ) )
		)
	);
}

/**
 * @testdox Should report differences by stable dot paths.
 */
public function test_reports_differences_by_stable_dot_paths(): void {
	$sut = new MultiCurrencySurfaceDiffer();

	$diff = $sut->diff(
		array( 'meta' => array( 'rate' => 0.82 ), 'currency' => 'GBP' ),
		array( 'meta' => array( 'rate' => 0.81 ), 'status' => 'actual' )
	);

	$this->assertSame( 0.82, $diff['meta.rate']['expected'] );
	$this->assertSame( 0.81, $diff['meta.rate']['actual'] );
	$this->assertSame( 'GBP', $diff['currency']['expected'] );
	$this->assertNull( $diff['currency']['actual'] );
	$this->assertNull( $diff['status']['expected'] );
	$this->assertSame( 'actual', $diff['status']['actual'] );
}
```

- [x] **Step 2: Run tests to verify they fail**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySurfaceDifferTest'
```

Expected: fail because `MultiCurrencySurfaceDiffer` does not exist.

- [x] **Step 3: Implement differ**

Implement recursive array diff with stable sorted keys and dot paths. Match the return shape:

```php
array<string,array{expected:mixed,actual:mixed}>
```

- [x] **Step 4: Run tests to verify they pass**

Run the same focused test. Expected: differ tests pass.

## Task 2: Shadow Comparison Record

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowComparisonTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowComparison.php`

- [x] **Step 1: Write failing comparison tests**

Test compact and full log payloads:

```php
/**
 * @testdox Should expose compact log payloads.
 */
public function test_exposes_compact_log_payloads(): void {
	$sut = new MultiCurrencyShadowComparison(
		'unit_test',
		123,
		array( 'meta' => array( 'rate' => 0.81 ) ),
		array( 'meta' => array( 'rate' => 0.82 ) ),
		array( 'meta.rate' => array( 'expected' => 0.82, 'actual' => 0.81 ) ),
		1.5
	);

	$payload = $sut->to_log_array();

	$this->assertSame( 'unit_test', $payload['trigger'] );
	$this->assertSame( 123, $payload['order_id'] );
	$this->assertSame( MultiCurrencyShadowComparison::COMPARISON_TYPE_ORDER_META, $payload['comparison_type'] );
	$this->assertTrue( $payload['independent_native_computation'] );
	$this->assertTrue( $payload['has_diff'] );
	$this->assertArrayHasKey( 'actual_hash', $payload );
	$this->assertArrayHasKey( 'native_computed_hash', $payload );
	$this->assertArrayNotHasKey( 'actual', $payload );
	$this->assertArrayNotHasKey( 'native_computed', $payload );
}

/**
 * @testdox Should include full surfaces when requested.
 */
public function test_includes_full_surfaces_when_requested(): void {
	$sut = new MultiCurrencyShadowComparison(
		'unit_test',
		123,
		array( 'meta' => array( 'rate' => 0.81 ) ),
		array( 'meta' => array( 'rate' => 0.82 ) ),
		array(),
		1.5
	);

	$payload = $sut->to_log_array( true );

	$this->assertSame( 0.81, $payload['actual']['meta']['rate'] );
	$this->assertSame( 0.82, $payload['native_computed']['meta']['rate'] );
}
```

- [x] **Step 2: Run tests to verify they fail**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyShadowComparisonTest'
```

Expected: fail because `MultiCurrencyShadowComparison` does not exist.

- [x] **Step 3: Implement comparison**

Use fields and methods matching the test, with comparison type:

```php
const COMPARISON_TYPE_ORDER_META = 'b1_multi_currency_order_meta_projection';
```

- [x] **Step 4: Run tests to verify they pass**

Run the same focused test. Expected: comparison tests pass.

## Task 3: Shadow Mode Observer

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowModeTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php`

- [x] **Step 1: Write failing registration tests**

Cover disabled-by-default and plugin-owner-only hook registration:

```php
/**
 * @testdox Shadow mode registers no hooks by default.
 */
public function test_registers_no_hooks_by_default(): void {
	$this->fake_plugin( true );

	$this->sut->register();

	$this->assertFalse( has_action( 'woocommerce_new_order', array( $this->sut, 'handle_woocommerce_new_order' ) ) );
	$this->assertFalse( has_action( 'woocommerce_order_refunded', array( $this->sut, 'handle_woocommerce_order_refunded' ) ) );
}

/**
 * @testdox Shadow mode hooks only when enabled and plugin owns multi-currency.
 */
public function test_registers_hooks_only_when_enabled_and_plugin_owns_multi_currency(): void {
	$this->fake_plugin( true );
	add_filter( MultiCurrencyShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );

	$this->sut->register();

	$this->assertSame( 100, has_action( 'woocommerce_new_order', array( $this->sut, 'handle_woocommerce_new_order' ) ) );
	$this->assertSame( 100, has_action( 'woocommerce_order_refunded', array( $this->sut, 'handle_woocommerce_order_refunded' ) ) );
}
```

- [x] **Step 2: Write failing record tests**

Add order/refund record tests using a logger fake and an in-memory projection service subclass:

```php
/**
 * @testdox Should record order meta comparisons without mutating orders.
 */
public function test_records_order_meta_comparison_without_mutating_orders(): void {
	$logger = $this->fake_logger();
	$order  = wc_create_order();
	$order->set_currency( 'GBP' );
	$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', 0.81 );
	$order->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
	$order->save();
	$before = $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true );

	$comparison = $this->sut->record_order_shadow( wc_get_order( $order->get_id() ), 'unit_test' );
	$after      = wc_get_order( $order->get_id() )->get_meta( '_wcpay_multi_currency_order_exchange_rate', true );

	$this->assertInstanceOf( MultiCurrencyShadowComparison::class, $comparison );
	$this->assertSame( 0.81, $before );
	$this->assertSame( 0.81, $after );
	$this->assertSame( 0.82, $comparison->get_native_computed()['meta']['_wcpay_multi_currency_order_exchange_rate'] );
	$this->assertSame( 0.81, $comparison->get_actual()['meta']['_wcpay_multi_currency_order_exchange_rate'] );
	$this->assertArrayHasKey( 'meta._wcpay_multi_currency_order_exchange_rate', $comparison->get_diff() );
	$this->assertSame( MultiCurrencyShadowMode::LOG_SOURCE, $logger->entries[0]['context']['source'] );
}
```

- [x] **Step 3: Run tests to verify they fail**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyShadowModeTest'
```

Expected: fail because `MultiCurrencyShadowMode` does not exist.

- [x] **Step 4: Implement shadow mode**

Implement:

- Constants:
    - `FILTER_SHADOW_ENABLED = 'woocommerce_native_multi_currency_shadow_mode_enabled'`
    - `FILTER_LOG_FULL_SURFACES = 'woocommerce_native_multi_currency_shadow_mode_log_full_surfaces'`
    - `LOG_SOURCE = 'native-multi-currency-shadow'`
- `init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyPriceProjectionService $projection_service, MultiCurrencySurfaceDiffer $differ, LegacyProxy $legacy_proxy ): void`
- `register()` hooks:
    - `woocommerce_new_order`, priority `100`, accepted args `2`
    - `woocommerce_order_refunded`, priority `100`, accepted args `2`
- Public `record_order_shadow( \WC_Order $order, string $trigger ): MultiCurrencyShadowComparison`
- Public `record_refund_shadow( \WC_Order $order, \WC_Order $refund, string $trigger ): MultiCurrencyShadowComparison`
- Private actual-surface readers that only call `get_meta()`.

- [x] **Step 5: Run tests to verify they pass**

Run the focused shadow mode test. Expected: shadow mode tests pass.

## Task 4: Bootstrap Registration

**Files:**

- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowModeTest.php`

- [x] **Step 1: Add a failing bootstrap-registration assertion**

Extend the registration test to assert the class can be obtained from the DI container and registers no hooks by default.

- [x] **Step 2: Register the shadow mode in WooCommerce bootstrap**

Add after native payments shadow registration:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode::class )->register();
```

- [x] **Step 3: Run focused tests**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyShadowModeTest'
```

Expected: tests pass.

## Task 5: Changelog, Regression, Review, and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b1e-multi-currency-shadow`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

```text
Significance: minor
Type: add

Add native payments B1e multi-currency shadow comparison.
```

- [ ] **Step 2: Run full focused regression**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: all tests pass.

- [ ] **Step 3: Run static checks**

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse src/Internal/MultiCurrency --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency includes/class-woocommerce.php
```

Expected: no PHPStan or PHPCS errors. If directory PHPStan hits the repo-level
unmatched ignore-pattern issue, rerun with explicit multi-currency source files
and record that explanation.

- [ ] **Step 4: Run staged and markdown checks**

```bash
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-15-core-native-payments-b1e.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

Expected: all checks pass.

- [ ] **Step 5: Request focused review**

Ask a reviewer to inspect the B1e shadow mode for accidental mutation,
registration when plugin does not own multi-currency, key drift, logging shape,
and test gaps. Reconcile confirmed findings before commit.

- [ ] **Step 6: Commit**

```bash
git add plugins/woocommerce/changelog/add-native-payments-b1e-multi-currency-shadow plugins/woocommerce/src/Internal/MultiCurrency/Shadow plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Shadow plugins/woocommerce/includes/class-woocommerce.php
git commit -m "feat(payments): add native payments B1e multi-currency shadow"
```

## Self-Review

- Spec coverage: This covers B1e shadow comparison for order/refund meta only. It does not compare displayed prices, cart totals, REST responses, geolocation, switchers, analytics, or frontend assets.
- Placeholder scan: No TBD/TODO placeholders remain.
- Type consistency: Shadow mode consumes `MultiCurrencyRuntimeArbiter`, `MultiCurrencyPriceProjectionService`, `MultiCurrencySurfaceDiffer`, `MultiCurrencyShadowComparison`, and `LegacyProxy` consistently across tasks.
