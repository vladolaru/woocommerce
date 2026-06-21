# Core Native Payments B2m Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Match WooPayments order-currency initialization behavior when order
lookup happens from native multi-currency frontend formatting.

**Architecture:** Keep the change in
`MultiCurrencyFrontendCurrenciesController::init_order_currency()`. Native
should temporarily remove its currency-format filters while resolving an order,
restore them immediately after lookup, and fall back to the selected currency
when no order resolves, matching WooPayments'
`FrontendCurrencies::init_order_currency()`.

**Tech Stack:** WooCommerce PHP, PHPUnit via `pnpm test:php:env`, PHPCS,
PHPStan, markdownlint.

---

## File Map

- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`

    - Add filter-removal/restoration around `wc_get_order()`.
    - Set `order_currency` to the selected currency when `wc_get_order()`
      returns false.

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`

    - Add red coverage for invalid order fallback.
    - Add red coverage proving native currency-format filters are absent during
      order lookup and restored afterward.

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2m-multi-currency-order-init-parity`

    - Changelog entry for the merchant-facing order-formatting parity fix.

- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`

    - Record scope, red/green checks, static gates, commit hash, and WPCOM
      read-only status.

- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

    - Append B2m gate evidence after verification.

## Task 1: Order Currency Initialization Parity

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2m-multi-currency-order-init-parity`

- [ ] **Step 1: Write the failing selected-currency fallback test**

Add this test near the existing order-currency initialization tests:

```php
/**
 * @testdox Should fall back to selected currency when order lookup fails.
 */
public function test_initializes_order_currency_to_selected_currency_when_order_lookup_fails(): void {
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$this->assertSame( 'missing-order', $sut->init_order_currency( 'missing-order' ) );

	$this->assertSame( 'GBP', $sut->get_order_currency() );
	$this->assertSame( 'GBP', $sut->get_woocommerce_currency( 'USD' ) );
}
```

- [ ] **Step 2: Write the failing filter guard test**

Add this test near the fallback test:

```php
/**
 * @testdox Should remove frontend currency filters while resolving order IDs.
 */
public function test_removes_frontend_currency_filters_while_resolving_order_ids(): void {
	$order = wc_create_order();
	$order->set_currency( 'JPY' );
	$order->save();
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );
	$sut->register();

	$observed_filters = null;
	add_filter(
		'woocommerce_order_class',
		function ( $class_name ) use ( $sut, &$observed_filters ) {
			$observed_filters = array(
				'wc_get_price_decimals'           => has_filter( 'wc_get_price_decimals', array( $sut, 'get_price_decimals' ) ),
				'wc_get_price_decimal_separator' => has_filter( 'wc_get_price_decimal_separator', array( $sut, 'get_price_decimal_separator' ) ),
				'wc_get_price_thousand_separator' => has_filter( 'wc_get_price_thousand_separator', array( $sut, 'get_price_thousand_separator' ) ),
				'woocommerce_price_format'       => has_filter( 'woocommerce_price_format', array( $sut, 'get_woocommerce_price_format' ) ),
			);

			return $class_name;
		},
		10,
		1
	);

	try {
		$this->assertSame( $order->get_id(), $sut->init_order_currency( $order->get_id() ) );
	} finally {
		remove_all_filters( 'woocommerce_order_class' );
	}

	$this->assertSame(
		array(
			'wc_get_price_decimals'           => false,
			'wc_get_price_decimal_separator' => false,
			'wc_get_price_thousand_separator' => false,
			'woocommerce_price_format'       => false,
		),
		$observed_filters
	);
	$this->assertSame( 900, has_filter( 'wc_get_price_decimals', array( $sut, 'get_price_decimals' ) ) );
	$this->assertSame( 900, has_filter( 'wc_get_price_decimal_separator', array( $sut, 'get_price_decimal_separator' ) ) );
	$this->assertSame( 900, has_filter( 'wc_get_price_thousand_separator', array( $sut, 'get_price_thousand_separator' ) ) );
	$this->assertSame( 900, has_filter( 'woocommerce_price_format', array( $sut, 'get_woocommerce_price_format' ) ) );
}
```

- [ ] **Step 3: Run the focused red test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: FAIL before implementation because invalid order lookup leaves
`order_currency` null and the currency-format filters remain registered while
`wc_get_order()` resolves the order.

- [ ] **Step 4: Implement minimal production behavior**

In `init_order_currency()`, keep the existing early return. For ID lookups,
remove these callbacks before `wc_get_order()` and restore them afterward:

```php
remove_filter( 'woocommerce_price_format', array( $this, 'get_woocommerce_price_format' ), 900 );
remove_filter( 'wc_get_price_thousand_separator', array( $this, 'get_price_thousand_separator' ), 900 );
remove_filter( 'wc_get_price_decimal_separator', array( $this, 'get_price_decimal_separator' ), 900 );
remove_filter( 'wc_get_price_decimals', array( $this, 'get_price_decimals' ), 900 );
```

Restore the same four callbacks at priority `900`. If no order resolves, set:

```php
$this->order_currency = $this->get_frontend_projection_service()->get_woocommerce_currency();
```

Return the original argument in the fallback path.

- [ ] **Step 5: Run focused green tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: PASS, with the test count increased by 2 from B2l.

- [ ] **Step 6: Add changelog entry**

Create:

```text
Significance: minor
Type: fix

Match WooPayments order currency initialization in native multi-currency.
```

- [ ] **Step 7: Run B2m regression and static gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRequestContextTest|MultiCurrencyRestRequestOverrideControllerTest|MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyOrderContextServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2m.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

Expected: all commands pass. The PHPUnit count should increase by 2 from the
B2l focused multi-currency regression.

- [ ] **Step 8: Stage only B2m files and commit**

Run:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php \
	plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php \
	plugins/woocommerce/changelog/add-native-payments-b2m-multi-currency-order-init-parity
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
git commit
```

Expected commit subject:

```text
fix(payments): match multi-currency order init behavior
```

## Self-Review

- Spec coverage: Covers WooPayments `FrontendCurrencies::init_order_currency()`
  parity for invalid/stale order IDs and recursive-format-filter avoidance.
  It does not attempt compatibility classes, switcher UI, Storefront, or
  REST/settings parity.
- Placeholder scan: No placeholders or deferred implementation steps.
- Type consistency: The plan uses existing
  `MultiCurrencyFrontendCurrenciesController` and
  `MultiCurrencyFrontendProjectionService` method names.
