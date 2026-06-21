# Core Native Payments B2ak Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments' My Account subscription total multi-currency formatting behavior in the Core-native Subscriptions compatibility runtime.

**Architecture:** Extend the existing native Subscriptions compatibility controller from B2af/B2aj. Register the three My Account filters at WooPayments-compatible priority 50, keep the current subscription only while Subscriptions formats an account total, let the existing selected-currency filter return that subscription currency, and append the currency code to `wc_price` output when additional currencies are enabled and the rendered text does not already include the code.

**Tech Stack:** WooCommerce Core PHP, WooCommerce Subscriptions hooks, native multi-currency state builder, WordPress `wc_price` filter, PHPUnit, PHPStan, PHPCS.

---

## File Structure

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php`
    - Add My Account subscription total hooks to the preserved manifest.
    - Add pure helpers for current-subscription capture and explicit currency-code suffixing.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`
    - Track the current My Account subscription while Subscriptions formats totals.
    - Override selected currency from that subscription.
    - Append the subscription currency code to `wc_price` output when needed.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php`
    - Assert the expanded hook manifest and pure helper behavior.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php`
    - Assert hook registration, capture/clear behavior, selected-currency override, and explicit formatting.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the runtime manifest includes the My Account filters.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2ak-subscriptions-account-totals`
    - Patch changelog entry for My Account subscription total formatting parity.

## Task 1: Projection Tests

**Files:**

- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php`

- [ ] **Step 1: Add failing manifest expectations**

Add these filters to the expected Subscriptions manifest:

```php
array(
    'hook'          => 'woocommerce_subscription_price_string_details',
    'callback'      => 'maybe_set_current_my_account_subscription',
    'priority'      => 50,
    'accepted_args' => 2,
),
array(
    'hook'          => 'woocommerce_get_formatted_subscription_total',
    'callback'      => 'maybe_clear_current_my_account_subscription',
    'priority'      => 50,
    'accepted_args' => 2,
),
array(
    'hook'          => 'wc_price',
    'callback'      => 'maybe_get_explicit_format_for_subscription_total',
    'priority'      => 50,
    'accepted_args' => 1,
),
```

- [ ] **Step 2: Add failing pure helper tests**

Assert:

```php
$this->assertTrue(
    MultiCurrencySubscriptionsCompatibilityProjectionService::should_set_current_my_account_subscription( true, false )
);
$this->assertTrue(
    MultiCurrencySubscriptionsCompatibilityProjectionService::should_set_current_my_account_subscription( false, true )
);
$this->assertFalse(
    MultiCurrencySubscriptionsCompatibilityProjectionService::should_set_current_my_account_subscription( false, false )
);
```

Assert explicit formatting:

```php
$this->assertSame(
    '<span>$10.00</span> EUR',
    MultiCurrencySubscriptionsCompatibilityProjectionService::get_explicit_subscription_total_price_html( '<span>$10.00</span>', 'EUR', true )
);
$this->assertSame(
    '<span>$10.00 EUR</span>',
    MultiCurrencySubscriptionsCompatibilityProjectionService::get_explicit_subscription_total_price_html( '<span>$10.00 EUR</span>', 'EUR', true )
);
$this->assertSame(
    '<span>$10.00</span>',
    MultiCurrencySubscriptionsCompatibilityProjectionService::get_explicit_subscription_total_price_html( '<span>$10.00</span>', 'EUR', false )
);
```

- [ ] **Step 3: Run projection red**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityProjectionServiceTest
```

Expected: FAIL because the manifest entries and pure helpers are missing.

## Task 2: Controller Tests

**Files:**

- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`

- [ ] **Step 1: Add failing hook registration assertions**

Add the three hooks to the controller cleanup list and assert they register at priority 50 for Core-owned frontend runtime and do not register when blocked.

- [ ] **Step 2: Add failing current-subscription tests**

Use the deterministic backtrace seam:

```php
$subscription = $this->create_subscription( 'EUR' );
$details      = array( 'price' => 'placeholder' );
$sut          = $this->create_controller();
$sut->set_backtrace_calls( array( 'WC_Subscription->get_formatted_order_total' ) );

$this->assertSame(
    $details,
    $sut->maybe_set_current_my_account_subscription( $details, $subscription )
);
$this->assertSame( 'EUR', $sut->override_selected_currency( false ) );
$this->assertSame(
    '<span>$10.00</span> EUR',
    $sut->maybe_get_explicit_format_for_subscription_total( '<span>$10.00</span>' )
);
$this->assertSame(
    '<span>$10.00</span> EUR',
    $sut->maybe_clear_current_my_account_subscription( '<span>$10.00</span> EUR', $subscription )
);
$this->assertFalse( $sut->override_selected_currency( false ) );
```

Also assert the controller does not set current subscription without the My Account backtrace, preserves existing selected-currency overrides, does not duplicate a currency code already present in the HTML text, and does not append a code when no additional currencies are enabled.

- [ ] **Step 3: Run controller red**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityControllerTest
```

Expected: FAIL because the My Account handlers and test seam are missing.

## Task 3: Implementation

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`

- [ ] **Step 1: Add projection helpers**

Implement:

```php
public static function should_set_current_my_account_subscription(
    bool $is_my_subscriptions_template_context,
    bool $is_formatted_order_total_context
): bool {
    return $is_my_subscriptions_template_context || $is_formatted_order_total_context;
}

public static function get_explicit_subscription_total_price_html(
    string $html_price,
    ?string $currency_code,
    bool $has_additional_currencies_enabled
): string {
    if ( ! $has_additional_currencies_enabled || null === $currency_code || '' === trim( $currency_code ) ) {
        return $html_price;
    }

    $price_to_check = html_entity_decode( wp_strip_all_tags( $html_price ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

    return false === strpos( $price_to_check, trim( $currency_code ) )
        ? $html_price . ' ' . strtoupper( trim( $currency_code ) )
        : $html_price;
}
```

- [ ] **Step 2: Add controller state and handlers**

Add:

- `private ?object $current_my_account_subscription = null;`
- `maybe_set_current_my_account_subscription( $subscription_details, $subscription ): array`
- `maybe_clear_current_my_account_subscription( $formatted, $subscription ): string`
- `maybe_get_explicit_format_for_subscription_total( $html_price ): string`

Call the pure helpers and keep the state object only when it exposes `get_currency()`.

- [ ] **Step 3: Route selected-currency override**

At the start of `override_selected_currency()` after the existing recursion/existing override guard, return the current My Account subscription currency if one is active.

- [ ] **Step 4: Add additional-currencies state helper**

Use a lazy `MultiCurrencyStateBuilder` to call `build()->has_additional_currencies_enabled()` for explicit formatting. Add a test setter so controller tests can inject deterministic state.

## Task 4: Gates and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2ak-subscriptions-account-totals`
- Modify logs:
    - `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
    - `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Run focused regression**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyStateBuilderTest'
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
Comment: Add native My Account subscription total formatting for multi-currency.
```

- [ ] **Step 4: Commit source and changelog separately**

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php
git commit -m "fix(payments): add subscriptions account total formatting"
```

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2ak-subscriptions-account-totals
git commit -m "chore(payments): add subscriptions account totals changelog"
```

