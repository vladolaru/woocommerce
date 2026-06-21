# Core Native Payments B2i Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Use explicit order currency context in native multi-currency frontend formatting.

**Architecture:** Add an order-currency override property to `MultiCurrencyFrontendCurrenciesController`. Initialize it from explicit `WC_Order` objects or order IDs, pass it into existing frontend projection methods, and clear it after formatted totals. Keep WooPayments' query-var/backtrace heuristics and automatic order-total initialization for a later B2 slice.

**Tech Stack:** WooCommerce core PHP, WC order CRUD, native multi-currency frontend controller/projection, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments parity rule:

- `FrontendCurrencies::init_order_currency()` sets an `order_currency` override from a `WC_Order` object or order ID.
- Currency formatting callbacks use `order_currency` when it is set.
- `maybe_clear_order_currency_after_formatted_order_total()` clears `order_currency` after formatted order totals in order-context flows.
- B2i implements only explicit order ID/object initialization and clearing. It does not implement WooPayments' `should_use_order_currency()` backtrace/page heuristics or query-var initialization yet.

## File Structure

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
    - Add nullable `order_currency` state.
    - Pass `order_currency` into frontend projection formatting methods.
    - Initialize `order_currency` from `WC_Order` or order ID in `init_order_currency()`.
    - Clear `order_currency` in `maybe_clear_order_currency_after_formatted_order_total()`.
    - Add `get_order_currency()` to match WooPayments' public diagnostic getter.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
    - Assert explicit order currency initialization affects formatting.
    - Assert formatted-total callback clears order currency.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2i-multi-currency-order-context`

## Tasks

### Task 1: Explicit Order Currency Context

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`

- [ ] **Step 1: Write failing order-context tests**

Add tests:

```php
public function test_initializes_order_currency_from_order_id_for_formatting(): void {
	$order = wc_create_order();
	$order->set_currency( 'JPY' );
	$order->save();
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$this->assertSame( $order->get_id(), $sut->init_order_currency( $order->get_id() ) );
	$this->assertSame( 'JPY', $sut->get_order_currency() );
	$this->assertSame( 'JPY', $sut->get_woocommerce_currency( 'USD' ) );
	$this->assertSame( 0, $sut->get_price_decimals( 2 ) );
}

public function test_clears_order_currency_after_formatted_order_total(): void {
	$order = wc_create_order();
	$order->set_currency( 'JPY' );
	$order->save();
	$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

	$sut->init_order_currency( $order );
	$this->assertSame( '$42.00', $sut->maybe_clear_order_currency_after_formatted_order_total( '$42.00', $order, '', false ) );

	$this->assertNull( $sut->get_order_currency() );
	$this->assertSame( 'GBP', $sut->get_woocommerce_currency( 'USD' ) );
}
```

Update the deterministic projection service test double so methods return the optional order currency when provided:

```php
public function get_woocommerce_currency( ?string $order_currency = null ): string {
	return $order_currency ?? 'GBP';
}

public function get_price_decimals( int $decimals, ?string $order_currency = null ): int {
	return 'JPY' === $order_currency ? 0 : 2;
}
```

- [ ] **Step 2: Run red controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: fail because order currency is not tracked and `get_order_currency()` is missing.

- [ ] **Step 3: Implement explicit order currency state**

Add:

```php
private ?string $order_currency = null;

public function get_order_currency(): ?string {
	return $this->order_currency;
}
```

Pass `$this->order_currency` to all frontend projection formatting methods:

```php
return $this->get_frontend_projection_service()->get_woocommerce_currency( $this->order_currency );
```

Implement `init_order_currency()`:

```php
public function init_order_currency( $order_id ) {
	if ( null !== $this->order_currency ) {
		return $order_id;
	}

	$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( $order_id );
	if ( $order ) {
		$this->order_currency = $order->get_currency();
		return $order->get_id();
	}

	return $order_id;
}
```

Clear in `maybe_clear_order_currency_after_formatted_order_total()`:

```php
if ( null !== $this->order_currency ) {
	$this->order_currency = null;
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

- Create: `plugins/woocommerce/changelog/add-native-payments-b2i-multi-currency-order-context`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use:

```text
Significance: minor
Type: fix

Use native payments multi-currency order context for formatting.
```

- [ ] **Step 2: Run regression and static checks**

Run focused regression including B2i:

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
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2i.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2i code/changelog**

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
fix(payments): use multi-currency order context
```

## Self-Review

- Spec coverage: B2i covers explicit order currency initialization and formatting only.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: order-currency property, getter, and callbacks are consistent.
