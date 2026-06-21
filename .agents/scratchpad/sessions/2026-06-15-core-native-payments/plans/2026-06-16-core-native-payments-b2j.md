# Core Native Payments B2j Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Initialize native multi-currency order formatting from WooCommerce
order query vars.

**Architecture:** Implement the existing
`MultiCurrencyFrontendCurrenciesController::init_order_currency_from_query_vars()`
callback by reading the global `WP::$query_vars` values WooPayments already
uses: `order-pay`, `order-received`, and `view-order`. Keep this slice narrow:
it initializes explicit query-var orders only and leaves WooPayments'
`should_use_order_currency()` page/backtrace heuristic plus automatic order
total initialization for later B2 work.

**Tech Stack:** WooCommerce core PHP, WordPress `WP` query vars, native
multi-currency frontend controller, PHPUnit via
`pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments parity rule:

- `FrontendCurrencies::init_order_currency_from_query_vars()` checks
  `order-pay`, then `order-received`, then `view-order`.
- The first non-empty query var is passed into `init_order_currency()`.
- The method returns no value.
- B2j does not implement the private WooPayments
  `should_use_order_currency()` heuristic. The method will be callable from the
  existing `before_woocommerce_pay` hook and direct tests only.

## File Structure

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
    - Add query-var initialization coverage for each supported query var.
    - Add precedence coverage when more than one order query var is present.
    - Add empty-query-var no-op coverage.
    - Add a small helper that temporarily swaps global `$wp->query_vars`.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
    - Implement `init_order_currency_from_query_vars()` against the global
      `WP` instance.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2j-multi-currency-order-query-vars`

## Tasks

### Task 1: Query-Var Order Currency Initialization

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`

- [ ] **Step 1: Write failing query-var tests**

Update the existing safe-pass-through test description so it no longer claims
all order-context callbacks are pass-throughs:

```php
/**
 * @testdox Should leave deferred order-context callbacks as safe pass-throughs.
 */
public function test_deferred_order_context_callbacks_are_safe_pass_throughs(): void {
```

Add these tests after the existing explicit-order tests:

```php
/**
 * @testdox Should initialize order currency from order query vars.
 *
 * @dataProvider order_query_var_provider
 *
 * @param string $query_var Query var name.
 */
public function test_initializes_order_currency_from_order_query_vars( string $query_var ): void {
	$order = wc_create_order();
	$order->set_currency( 'JPY' );
	$order->save();
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$this->with_query_vars(
		array( $query_var => $order->get_id() ),
		function () use ( $sut, $order ): void {
			$sut->init_order_currency_from_query_vars();

			$this->assertSame( 'JPY', $sut->get_order_currency() );
			$this->assertSame( 'JPY', $sut->get_woocommerce_currency( 'USD' ) );
			$this->assertSame( 0, $sut->get_price_decimals( 2 ) );
			$this->assertSame( $order->get_id(), $sut->init_order_currency( $order->get_id() ) );
		}
	);
}

/**
 * Data provider for supported order query vars.
 *
 * @return array<string, array{string}>
 */
public function order_query_var_provider(): array {
	return array(
		'order-pay'      => array( 'order-pay' ),
		'order-received' => array( 'order-received' ),
		'view-order'     => array( 'view-order' ),
	);
}

/**
 * @testdox Should prefer order-pay before other order query vars.
 */
public function test_prefers_order_pay_before_other_order_query_vars(): void {
	$order_pay = wc_create_order();
	$order_pay->set_currency( 'JPY' );
	$order_pay->save();
	$order_received = wc_create_order();
	$order_received->set_currency( 'EUR' );
	$order_received->save();
	$view_order = wc_create_order();
	$view_order->set_currency( 'AUD' );
	$view_order->save();
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$this->with_query_vars(
		array(
			'order-pay'      => $order_pay->get_id(),
			'order-received' => $order_received->get_id(),
			'view-order'     => $view_order->get_id(),
		),
		function () use ( $sut ): void {
			$sut->init_order_currency_from_query_vars();

			$this->assertSame( 'JPY', $sut->get_order_currency() );
		}
	);
}

/**
 * @testdox Should ignore empty order query vars.
 */
public function test_ignores_empty_order_query_vars(): void {
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$this->with_query_vars(
		array(
			'order-pay'      => '',
			'order-received' => 0,
			'view-order'     => false,
		),
		function () use ( $sut ): void {
			$sut->init_order_currency_from_query_vars();

			$this->assertNull( $sut->get_order_currency() );
			$this->assertSame( 'GBP', $sut->get_woocommerce_currency( 'USD' ) );
		}
	);
}
```

Add this helper near the other private test helpers:

```php
/**
 * Run a callback with temporary WordPress query vars.
 *
 * @param array<string, mixed> $query_vars Query vars.
 * @param callable            $callback   Callback to run.
 */
private function with_query_vars( array $query_vars, callable $callback ): void {
	global $wp;

	if ( ! $wp instanceof \WP ) {
		$wp = new \WP();
	}

	$previous_query_vars = $wp->query_vars;
	$wp->query_vars      = $query_vars;

	try {
		$callback();
	} finally {
		$wp->query_vars = $previous_query_vars;
	}
}
```

- [ ] **Step 2: Run red controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: fail because `init_order_currency_from_query_vars()` still does
nothing, so `get_order_currency()` remains null.

- [ ] **Step 3: Implement query-var initialization**

Replace the placeholder with:

```php
/**
 * Initialize order-context currency from WooCommerce order query vars.
 */
public function init_order_currency_from_query_vars(): void {
	global $wp;

	if ( ! $wp instanceof \WP ) {
		return;
	}

	foreach ( array( 'order-pay', 'order-received', 'view-order' ) as $query_var ) {
		if ( ! empty( $wp->query_vars[ $query_var ] ) ) {
			$this->init_order_currency( $wp->query_vars[ $query_var ] );
			return;
		}
	}
}
```

- [ ] **Step 4: Run green controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: pass.

### Task 2: Verification, Changelog, Commit

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2j-multi-currency-order-query-vars`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use:

```text
Significance: minor
Type: fix

Use native payments multi-currency order query vars for formatting.
```

- [ ] **Step 2: Run regression and static checks**

Run focused regression including B2j:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRequestContextTest|MultiCurrencyRestRequestOverrideControllerTest|MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run static checks:

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2j.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2j code/changelog**

Do not stage `.agents/`, `docs/superpowers/`, or the external staging log.

- [ ] **Step 4: Run staged lint and commit**

Run:

```bash
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
git commit
```

Commit subject:

```text
fix(payments): use multi-currency order query vars
```

## Self-Review

- Spec coverage: B2j covers only `order-pay`, `order-received`, and
  `view-order` query-var initialization.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: query-var helper, controller method, and test assertions all
  use the same nullable order-currency state added in B2i.
