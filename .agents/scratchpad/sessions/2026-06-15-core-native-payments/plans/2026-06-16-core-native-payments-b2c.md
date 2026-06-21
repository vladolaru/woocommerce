# Core Native Payments B2c Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register native multi-currency frontend price conversion hooks only
when core owns multi-currency.

**Architecture:** Add a `MultiCurrencyFrontendPricesController` under
`src/Internal/MultiCurrency/` that mirrors WooPayments'
`FrontendPrices.php` hook surface behind `MultiCurrencyRuntimeArbiter`. Keep
price arithmetic in `MultiCurrencyPriceProjectionService`; add a small
projection helper for selected-currency price-filter query bounds. The
controller registers nothing in plugin/no-owner modes, honors preserved
compatibility filters, and leaves full compatibility class loading and frontend
JavaScript migration for later B2 slices.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Extend Price Projection Tests

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionServiceTest.php`

- [x] **Step 1: Write failing projection tests**

Add tests for converting price-filter query bounds from the selected currency
back into default/store currency:

```php
/**
 * @testdox Should project selected-currency price-filter bounds to default currency.
 */
public function test_projects_price_filter_bounds_to_default_currency(): void {
    $sut = $this->create_service( $this->create_state( 'GBP' ) );

    $this->assertSame( '10', $sut->get_price_filter_query_value( 8.2, '>=' ) );
    $this->assertSame( '11', $sut->get_price_filter_query_value( 8.21, '<=' ) );
}

/**
 * @testdox Should report whether selected and default currencies differ.
 */
public function test_reports_whether_selected_currency_differs_from_default(): void {
    $this->assertTrue(
        $this->create_service( $this->create_state( 'GBP' ) )
            ->should_project_between_selected_and_default_currency()
    );
    $this->assertFalse(
        $this->create_service( $this->create_state( 'USD' ) )
            ->should_project_between_selected_and_default_currency()
    );
}
```

- [x] **Step 2: Run projection tests to verify failure**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyPriceProjectionServiceTest
```

Expected: fail because the projection helper methods do not exist.

## Task 2: Add Frontend Price Controller Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php`

- [x] **Step 1: Write failing controller tests**

Create a `WC_Unit_Test_Case` with these behavior tests:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

$sut->register();

$this->assertFalse(
    has_filter( 'woocommerce_product_get_price', array( $sut, 'get_product_price_string' ) )
);
$this->assertFalse(
    has_filter( 'woocommerce_coupon_get_amount', array( $sut, 'get_coupon_amount' ) )
);
```

Also cover:

- core-owner mode registers the preserved B2a price hook group:
  product price hooks, variation hooks, shipping hooks, coupon hooks,
  `woocommerce_new_order`, `rest_post_dispatch`, and
  `query_loop_block_query_vars`.
- registered priorities match WooPayments: frontend price hooks at priority
  `99`, REST/query price-filter hooks at priority `10`.
- repeated `register()` calls do not duplicate callbacks.
- product, variation, shipping, coupon, order-meta, Store API price-range, and
  query-loop callbacks delegate to the price projection service.
- `wcpay_multi_currency_should_convert_product_price` and
  `wcpay_multi_currency_should_convert_coupon_amount` can block conversion.
- empty product/coupon amounts and percentage coupons pass through unchanged.

- [x] **Step 2: Run controller tests to verify failure**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendPricesControllerTest
```

Expected: fail because `MultiCurrencyFrontendPricesController` does not exist.

## Task 3: Implement Price Projection Helpers

**Files:**

- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionService.php`

- [x] **Step 1: Add projection helpers**

Add:

```php
public function should_project_between_selected_and_default_currency(): bool {
    $state = $this->state_builder->build();

    return $state->get_default_currency()->get_code() !== $state->get_selected_currency()->get_code();
}

public function get_price_filter_query_value( $amount, string $compare ): string {
    $state            = $this->state_builder->build();
    $converted_amount = $this->price_calculator->get_raw_conversion(
        (float) $amount,
        $state->get_default_currency()->get_code(),
        $state->get_selected_currency()->get_code(),
        $state->get_enabled_currencies()
    );

    return '<=' === $compare ? (string) ceil( $converted_amount ) : (string) floor( $converted_amount );
}
```

- [x] **Step 2: Run projection tests to verify pass**

Run the Task 1 command again.

Expected: pass.

## Task 4: Implement Frontend Price Controller

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyFrontendPricesController` with:

```php
final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void {}
public function set_price_projection_service( MultiCurrencyPriceProjectionService $price_projection_service ): void {}
public function register() {}
public function get_product_price( $price, $product = null ) {}
public function get_product_price_string( $price, $product = null ): string {}
public function get_variation_price_range( $variation_prices ) {}
public function add_exchange_rate_to_variation_prices_hash( $prices_hash ) {}
public function convert_shipping_method_rate_cost( $args ) {}
public function get_coupon_amount( $amount, $coupon ) {}
public function get_coupon_min_max_amount( $amount ) {}
public function convert_free_shipping_method_min_amount( $methods ) {}
public function add_order_meta( $order_id, $order ): void {}
public function maybe_modify_price_ranges_rest_response( $response, $server, $request ) {}
public function maybe_modify_price_ranges_query_var( $query, $block, $page ) {}
```

Implementation requirements:

- Use `MultiCurrencyRuntimeArbiter::should_core_register()` as the only
  registration gate.
- Use `has_filter()` checks before adding each hook, so repeated registration
  stays idempotent.
- Register exactly the B2a frontend price hook metadata.
- Lazy-build the default `MultiCurrencyPriceProjectionService` graph, matching
  the B2b frontend currencies controller pattern, so dormant/plugin-owned boot
  remains non-mutating.
- `get_product_price()` returns the original amount when the amount is empty
  or `wcpay_multi_currency_should_convert_product_price` returns false.
- `get_coupon_amount()` returns the original amount for empty values,
  percentage coupons, or when
  `wcpay_multi_currency_should_convert_coupon_amount` returns false.
- `convert_shipping_method_rate_cost()` preserves array-shaped shipping costs.
- `add_order_meta()` writes only the projected meta candidates returned by
  `MultiCurrencyPriceProjectionService::get_order_meta_candidates()`.
- Store API price-range conversion applies only to
  `/wc/store/v1/products/collection-data` responses with object-shaped
  `price_range`.
- Query-loop price-range conversion applies only to product queries with
  array-shaped `meta_query`, and only when selected/default currencies differ.
- Wire the controller into `WC::init_hooks()` next to the existing
  multi-currency frontend currency controller registration.

- [x] **Step 2: Run focused tests to verify pass**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFrontendPricesControllerTest|MultiCurrencyPriceProjectionServiceTest'
```

Expected: pass.

## Task 5: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2c-multi-currency-frontend-prices`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency frontend price hook controller.
```

- [x] **Step 2: Run regression and static checks**

Run the B0-B2c multi-currency regression filter by adding
`MultiCurrencyFrontendPricesControllerTest` to the B2b command.

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2c.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

If full-file PHPCS reports legacy `includes/class-woocommerce.php` warnings,
keep that file covered by the changed-line lint command instead.

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesControllerTest.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPriceProjectionServiceTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2c-multi-currency-frontend-prices
git commit -m "feat(payments): add native payments B2c multi-currency frontend price hooks"
```

Self-review:

- Plugin/no-owner modes register no frontend price hooks.
- Core-owner mode registers the preserved price hook surface once.
- Price callbacks delegate to the B1 price projection service.
- Compatibility conversion filters are honored without loading compatibility
  classes in this slice.
- No WPCOM or WooPayments client file is modified.
