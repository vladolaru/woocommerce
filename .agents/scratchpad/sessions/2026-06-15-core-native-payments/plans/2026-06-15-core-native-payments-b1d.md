# Native Payments B1d Multi-Currency Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a non-mutating multi-currency projection service that computes selected-currency prices and meta candidates for shadow comparison.

**Architecture:** Keep B1d read-only and side-effect-free. `MultiCurrencyPriceProjectionService` composes the B1c state builder with the B1b calculator and returns values later shadow hooks can compare against WooPayments output, but it does not register hooks, initialize sessions, refresh caches, or write order/refund meta.

**Tech Stack:** WooCommerce Core PHP 8.1-compatible code, `WC_Unit_Test_Case`, existing `MultiCurrencyState`, `MultiCurrencyStateBuilder`, `MultiCurrencyPriceCalculator`, and WooCommerce CRUD objects.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionService.php`
    - Public read-only methods:
        - `get_price( $price, string $type ): float`
        - `get_raw_conversion( float $amount, string $to_currency, string $from_currency ): float`
        - `get_order_meta_candidates( string $order_currency ): array`
        - `get_refund_meta_candidates( \WC_Order $order ): array`
    - Public constants for preserved meta keys:
        - `_wcpay_multi_currency_order_exchange_rate`
        - `_wcpay_multi_currency_order_default_currency`
        - `_wcpay_multi_currency_stripe_exchange_rate`
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionServiceTest.php`
    - Tests use an anonymous `MultiCurrencyStateBuilder` subclass that returns an in-memory `MultiCurrencyState`.
- Create `plugins/woocommerce/changelog/add-native-payments-b1d-multi-currency-projection`
    - WooCommerce changelog entry.

## Task 1: Selected-Currency Price Projection

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionServiceTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionService.php`

- [ ] **Step 1: Write the failing price projection tests**

Add tests that prove the service uses the selected currency from state:

```php
/**
 * @testdox Should project selected-currency product prices.
 */
public function test_projects_selected_currency_product_prices(): void {
	$state = $this->create_state( 'GBP' );
	$sut   = $this->create_service( $state );

	$this->assertSame( 8.4, $sut->get_price( '10.00', 'product' ) );
}

/**
 * @testdox Should project raw conversions between enabled currencies.
 */
public function test_projects_raw_conversions_between_enabled_currencies(): void {
	$state = $this->create_state( 'GBP' );
	$sut   = $this->create_service( $state );

	$this->assertSame( 10.0 * ( 0.82 / 1.25 ), $sut->get_raw_conversion( 10.0, 'GBP', 'CAD' ) );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPriceProjectionServiceTest'
```

Expected: fail because `MultiCurrencyPriceProjectionService` does not exist.

- [ ] **Step 3: Implement minimal projection service**

Create the class with constructor dependencies and the two price methods:

```php
class MultiCurrencyPriceProjectionService {
	private MultiCurrencyStateBuilder $state_builder;
	private MultiCurrencyPriceCalculator $price_calculator;

	public function __construct( MultiCurrencyStateBuilder $state_builder, MultiCurrencyPriceCalculator $price_calculator ) {
		$this->state_builder     = $state_builder;
		$this->price_calculator = $price_calculator;
	}

	public function get_price( $price, string $type ): float {
		$state = $this->state_builder->build();

		return $this->price_calculator->get_price( $price, $type, $state->get_selected_currency() );
	}

	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency ): float {
		$state = $this->state_builder->build();

		return $this->price_calculator->get_raw_conversion( $amount, $to_currency, $from_currency, $state->get_enabled_currencies() );
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run the same focused test command. Expected: price projection tests pass.

## Task 2: Order Meta Candidate Projection

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionServiceTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionService.php`

- [ ] **Step 1: Write failing order meta candidate tests**

Add tests for the preserved order meta keys:

```php
/**
 * @testdox Should project order exchange-rate meta for non-default orders.
 */
public function test_projects_order_exchange_rate_meta_for_non_default_orders(): void {
	$sut = $this->create_service( $this->create_state( 'GBP' ) );

	$this->assertSame(
		array(
			'_wcpay_multi_currency_order_exchange_rate'    => 0.82,
			'_wcpay_multi_currency_order_default_currency' => 'USD',
		),
		$sut->get_order_meta_candidates( 'GBP' )
	);
}

/**
 * @testdox Should not project order meta for default-currency orders.
 */
public function test_does_not_project_order_meta_for_default_currency_orders(): void {
	$sut = $this->create_service( $this->create_state( 'USD' ) );

	$this->assertSame( array(), $sut->get_order_meta_candidates( 'USD' ) );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPriceProjectionServiceTest'
```

Expected: fail because `get_order_meta_candidates()` does not exist.

- [ ] **Step 3: Implement order meta projection**

Add constants and method:

```php
public const META_KEY_ORDER_EXCHANGE_RATE = '_wcpay_multi_currency_order_exchange_rate';
public const META_KEY_ORDER_DEFAULT_CURRENCY = '_wcpay_multi_currency_order_default_currency';

public function get_order_meta_candidates( string $order_currency ): array {
	$state            = $this->state_builder->build();
	$default_currency = $state->get_default_currency();

	if ( $default_currency->get_code() === strtoupper( $order_currency ) ) {
		return array();
	}

	return array(
		self::META_KEY_ORDER_EXCHANGE_RATE    => $this->price_calculator->get_price( 1, 'exchange_rate', $state->get_selected_currency() ),
		self::META_KEY_ORDER_DEFAULT_CURRENCY => $default_currency->get_code(),
	);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run the focused test command. Expected: all projection tests pass.

## Task 3: Refund Meta Copy Candidate Projection

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionServiceTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionService.php`

- [ ] **Step 1: Write failing refund meta tests**

Add tests proving the method reads but does not write:

```php
/**
 * @testdox Should project refund meta copied from non-default orders.
 */
public function test_projects_refund_meta_from_non_default_orders(): void {
	$order = wc_create_order();
	$order->set_currency( 'GBP' );
	$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', 0.82 );
	$order->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
	$order->update_meta_data( '_wcpay_multi_currency_stripe_exchange_rate', 1.25 );
	$order->save();

	$sut = $this->create_service( $this->create_state( 'GBP' ) );

	$this->assertSame(
		array(
			'_wcpay_multi_currency_order_exchange_rate'    => 0.82,
			'_wcpay_multi_currency_order_default_currency' => 'USD',
			'_wcpay_multi_currency_stripe_exchange_rate'   => 1.25,
		),
		$sut->get_refund_meta_candidates( $order )
	);
}

/**
 * @testdox Should not project refund meta for default-currency orders.
 */
public function test_does_not_project_refund_meta_for_default_currency_orders(): void {
	$order = wc_create_order();
	$order->set_currency( 'USD' );
	$order->save();

	$sut = $this->create_service( $this->create_state( 'USD' ) );

	$this->assertSame( array(), $sut->get_refund_meta_candidates( $order ) );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run the focused test command. Expected: fail because `get_refund_meta_candidates()` does not exist.

- [ ] **Step 3: Implement refund meta projection**

Add the Stripe meta constant and method:

```php
public const META_KEY_STRIPE_EXCHANGE_RATE = '_wcpay_multi_currency_stripe_exchange_rate';

public function get_refund_meta_candidates( \WC_Order $order ): array {
	$state            = $this->state_builder->build();
	$default_currency = $state->get_default_currency();

	if ( $default_currency->get_code() === strtoupper( $order->get_currency() ) ) {
		return array();
	}

	$meta = array(
		self::META_KEY_ORDER_EXCHANGE_RATE    => $order->get_meta( self::META_KEY_ORDER_EXCHANGE_RATE, true ),
		self::META_KEY_ORDER_DEFAULT_CURRENCY => $order->get_meta( self::META_KEY_ORDER_DEFAULT_CURRENCY, true ),
	);

	$stripe_exchange_rate = $order->get_meta( self::META_KEY_STRIPE_EXCHANGE_RATE, true );
	if ( $stripe_exchange_rate ) {
		$meta[ self::META_KEY_STRIPE_EXCHANGE_RATE ] = $stripe_exchange_rate;
	}

	return $meta;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run the focused test command. Expected: all projection tests pass.

## Task 4: Changelog, Regression, Review, and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b1d-multi-currency-projection`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

```text
Significance: minor
Type: add

Add native payments B1d multi-currency shadow price projection.
```

- [ ] **Step 2: Run full focused regression**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: all tests pass.

- [ ] **Step 3: Run static checks**

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse src/Internal/MultiCurrency --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Expected: no PHPStan or PHPCS errors.

- [ ] **Step 4: Run staged and markdown checks**

```bash
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-15-core-native-payments-b1d.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

Expected: all checks pass.

- [ ] **Step 5: Request focused review**

Dispatch a code reviewer for the projection service and tests. Ask it to check for accidental mutation, key drift, projection mismatch against WooPayments, and test gaps. Reconcile confirmed findings before commit.

- [ ] **Step 6: Commit**

```bash
git add plugins/woocommerce/changelog/add-native-payments-b1d-multi-currency-projection plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionServiceTest.php
git commit -m "feat(payments): add native payments B1d multi-currency projection"
```

## Self-Review

- Spec coverage: This covers B1d projection only. It does not register WooCommerce filters, render frontend switchers, geolocate users, expose REST routes, or write order/refund meta.
- Placeholder scan: No TBD/TODO placeholders remain.
- Type consistency: The service consumes `MultiCurrencyStateBuilder`, `MultiCurrencyPriceCalculator`, `MultiCurrencyState`, and `WC_Order` exactly as defined in prior B1 slices.
