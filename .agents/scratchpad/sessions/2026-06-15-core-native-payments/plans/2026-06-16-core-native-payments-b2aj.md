# Core Native Payments B2aj Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Core-native multi-currency conversion for WooCommerce Subscriptions product prices and sign-up fees.

**Architecture:** Extend the existing native Subscriptions compatibility controller and projection service from B2af. Register the three WooPayments-compatible direct conversion filters only when Core owns multi-currency, reuse `MultiCurrencyPriceProjectionService` for product-price conversion, and preserve WooPayments' switch-cart proration guards for sign-up fees.

**Tech Stack:** WooCommerce Core PHP, WordPress/WooCommerce filters, native multi-currency runtime arbiter, `MultiCurrencyPriceProjectionService`, PHPUnit, PHPStan, PHPCS.

---

## File Structure

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php`
    - Add the direct Subscriptions price/sign-up fee hooks to the manifest.
    - Add pure predicates for direct subscription product price and sign-up fee conversion.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`
    - Register callable handlers through the existing manifest loop.
    - Convert direct subscription product prices through the native projection service.
    - Convert subscription sign-up fees while preserving switch/proration skip contexts.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php`
    - Assert the expanded hook manifest and pure predicate behavior.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php`
    - Assert hook registration and runtime behavior for subscription product prices and sign-up fees.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2aj-subscriptions-direct-conversion`
    - Patch changelog entry for the native Subscriptions direct conversion parity.

## Task 1: Projection Tests

**Files:**

- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php`

- [ ] **Step 1: Write failing manifest expectations**

Add these expected filters after the existing `wcpay_multi_currency_should_convert_coupon_amount` entry:

```php
array(
    'hook'          => 'woocommerce_subscriptions_product_price',
    'callback'      => 'get_subscription_product_price',
    'priority'      => 50,
    'accepted_args' => 2,
),
array(
    'hook'          => 'woocommerce_product_get__subscription_sign_up_fee',
    'callback'      => 'get_subscription_product_signup_fee',
    'priority'      => 50,
    'accepted_args' => 2,
),
array(
    'hook'          => 'woocommerce_product_variation_get__subscription_sign_up_fee',
    'callback'      => 'get_subscription_product_signup_fee',
    'priority'      => 50,
    'accepted_args' => 2,
),
```

- [ ] **Step 2: Write failing predicate tests**

Add tests that assert:

```php
$this->assertTrue(
    MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_subscription_product_price( '10.00', true )
);
$this->assertFalse(
    MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_subscription_product_price( 0, true )
);
$this->assertFalse(
    MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_subscription_product_price( '10.00', false )
);
```

Add a sign-up fee data provider that covers:

- Empty price does not convert.
- Non-switch product converts.
- Switch product in `WC_Subscriptions_Cart::set_subscription_prices_for_calculation` context does not convert.
- Switch product in repeated `WC_Subscriptions_Product::get_sign_up_fee` plus `WC_Cart->calculate_totals` context does not convert unless `WCS_Switch_Totals_Calculator->apportion_sign_up_fees` is present.
- Switch product with changed `_subscription_sign_up_fee` meta does not convert.

- [ ] **Step 3: Run projection red**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityProjectionServiceTest
```

Expected: FAIL because the manifest and predicate methods are missing.

- [ ] **Step 4: Implement projection methods**

Add static methods:

```php
public static function should_convert_subscription_product_price( $price, bool $should_convert_product_price ): bool {
    return (bool) $price && $should_convert_product_price;
}

public static function should_convert_subscription_signup_fee(
    $price,
    bool $is_switch_product,
    bool $is_subscription_price_setup_context,
    bool $is_switch_proration_context,
    bool $has_changed_signup_fee_meta
): bool {
    if ( ! $price ) {
        return false;
    }

    if ( ! $is_switch_product ) {
        return true;
    }

    return ! $is_subscription_price_setup_context
        && ! $is_switch_proration_context
        && ! $has_changed_signup_fee_meta;
}
```

- [ ] **Step 5: Run projection green**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityProjectionServiceTest
```

Expected: PASS.

## Task 2: Controller Tests

**Files:**

- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`

- [ ] **Step 1: Write failing hook registration test updates**

Add the three direct hooks to the `$hooks` cleanup list and assert:

```php
$this->assertSame( 50, has_filter( 'woocommerce_subscriptions_product_price', array( $sut, 'get_subscription_product_price' ) ) );
$this->assertSame( 50, has_filter( 'woocommerce_product_get__subscription_sign_up_fee', array( $sut, 'get_subscription_product_signup_fee' ) ) );
$this->assertSame( 50, has_filter( 'woocommerce_product_variation_get__subscription_sign_up_fee', array( $sut, 'get_subscription_product_signup_fee' ) ) );
```

Also add matching negative assertions to `assert_subscription_hooks_not_registered()`.

- [ ] **Step 2: Write failing product price conversion tests**

Use a deterministic fake `MultiCurrencyPriceProjectionService` that doubles product prices. Assert:

```php
$this->assertSame( 20.0, $sut->get_subscription_product_price( '10.00', $product ) );
$this->assertSame( 0, $sut->get_subscription_product_price( 0, $product ) );
```

Then add a renewal cart plus `WC_Cart_Totals->calculate_item_totals` backtrace and assert the original `10.00` is preserved.

- [ ] **Step 3: Write failing sign-up fee conversion tests**

Assert that:

- A normal sign-up fee converts through the product projection service.
- A switch cart sign-up fee is not converted during subscription price setup.
- A repeated switch cart sign-up fee is not converted during proration total calculation.
- The same repeated proration call converts when `WCS_Switch_Totals_Calculator->apportion_sign_up_fees` is present.
- A repeated switch cart sign-up fee is not converted if product meta `_subscription_sign_up_fee` already has changes.

- [ ] **Step 4: Run controller red**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityControllerTest
```

Expected: FAIL because the new handlers and test projection setter are missing.

## Task 3: Controller Implementation

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`

- [ ] **Step 1: Add price projection dependency**

Add a nullable `MultiCurrencyPriceProjectionService` property, a public `set_price_projection_service()` test seam, and a private lazy factory matching `MultiCurrencyFrontendPricesController`.

- [ ] **Step 2: Add direct product price handler**

Implement:

```php
public function get_subscription_product_price( $price, $product ) {
    if ( ! MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_subscription_product_price( $price, $this->should_convert_product_price( true, $product ) ) ) {
        return $price;
    }

    return $this->get_price_projection_service()->get_price( $price, 'product' );
}
```

- [ ] **Step 3: Add sign-up fee handler**

Track the previous switch cart item key, detect the selected switch product by `variation_id` or `product_id`, and pass these booleans into the projection predicate:

- Product matches active switch cart item.
- `WC_Subscriptions_Cart::set_subscription_prices_for_calculation` is in the backtrace.
- Previous key matches current key, `WC_Subscriptions_Product::get_sign_up_fee` and `WC_Cart->calculate_totals` are in the backtrace, and `WCS_Switch_Totals_Calculator->apportion_sign_up_fees` is not.
- Previous key matches current key and product meta `_subscription_sign_up_fee` has changes.

- [ ] **Step 4: Run controller green**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityControllerTest
```

Expected: PASS.

## Task 4: Gates and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2aj-subscriptions-direct-conversion`
- Modify logs:
    - `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
    - `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Run focused multi-currency regression**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyFrontendPricesControllerTest'
```

- [ ] **Step 2: Run static checks**

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

- [ ] **Step 3: Add changelog**

```text
Significance: patch
Type: fix
Comment: Add native multi-currency conversion for WooCommerce Subscriptions product prices and sign-up fees.
```

- [ ] **Step 4: Commit source and changelog separately**

Commit source/tests first:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php
git commit -m "fix(payments): add subscriptions price conversion"
```

Commit changelog second:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2aj-subscriptions-direct-conversion
git commit -m "chore(payments): add subscriptions conversion changelog"
```

