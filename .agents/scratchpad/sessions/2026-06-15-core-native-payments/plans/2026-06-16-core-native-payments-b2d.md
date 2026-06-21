# Core Native Payments B2d Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments-compatible selected-currency compatibility
filters in native multi-currency state selection.

**Architecture:** Extend `MultiCurrencyStateBuilder` so selected currency is
still built from preserved state, but request/compatibility filters can force
store currency or override the selected currency before frontend price
projection runs. This slice applies the preserved filters only; it does not
load compatibility integration classes or move frontend JavaScript.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add State Builder Compatibility Filter Tests

**Files:**

- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php`

- [x] **Step 1: Write failing tests**

Add tests:

```php
/**
 * @testdox Should select compatibility override currency when enabled.
 */
public function test_selects_compatibility_override_currency_when_enabled(): void {
    update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
    update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
    update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
    add_filter(
        'wcpay_multi_currency_override_selected_currency',
        static function () {
            return 'GBP';
        }
    );

    $state = $this->create_builder()->build();

    $this->assertSame( 'GBP', $state->get_selected_currency()->get_code() );
}

/**
 * @testdox Should force store currency when compatibility filter requests it.
 */
public function test_forces_store_currency_when_compatibility_filter_requests_it(): void {
    $user_id = self::factory()->user->create();
    wp_set_current_user( $user_id );
    update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
    update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
    update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
    update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
    add_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' );

    $state = $this->create_builder()->build();

    $this->assertSame( 'USD', $state->get_selected_currency()->get_code() );
}

/**
 * @testdox Should fall back to default when compatibility override is not enabled.
 */
public function test_falls_back_to_default_when_compatibility_override_is_not_enabled(): void {
    $user_id = self::factory()->user->create();
    wp_set_current_user( $user_id );
    update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
    update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
    update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
    update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
    add_filter(
        'wcpay_multi_currency_override_selected_currency',
        static function () {
            return 'EUR';
        }
    );

    $state = $this->create_builder()->build();

    $this->assertSame( 'USD', $state->get_selected_currency()->get_code() );
}
```

Also update `tear_down()` to remove both compatibility filters.

- [x] **Step 2: Run tests to verify failure**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyStateBuilderTest
```

Expected: fail because the state builder ignores the compatibility filters.

## Task 2: Apply Compatibility Filters In State Builder

**Files:**

- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php`

- [x] **Step 1: Add selected-currency filter application**

Add constants:

```php
private const FILTER_OVERRIDE_SELECTED_CURRENCY    = 'wcpay_multi_currency_override_selected_currency';
private const FILTER_SHOULD_RETURN_STORE_CURRENCY = 'wcpay_multi_currency_should_return_store_currency';
```

Replace direct stored-currency selection with a helper:

```php
$selected_code = $this->get_selected_currency_code( $default_code );
$selected      = $selected_code && isset( $enabled[ $selected_code ] )
    ? $enabled[ $selected_code ]
    : $default;
```

Add:

```php
private function get_selected_currency_code( string $default_code ): ?string {
    /**
     * Filters whether native multi-currency should force store currency.
     *
     * @param bool $should_return_store_currency Whether to force store currency.
     *
     * @since 11.0.0
     */
    if ( (bool) apply_filters( self::FILTER_SHOULD_RETURN_STORE_CURRENCY, false ) ) {
        return $default_code;
    }

    /**
     * Filters the selected native multi-currency code.
     *
     * @param string|false $currency_code Override currency code, or false to use stored state.
     *
     * @since 11.0.0
     */
    $override_currency_code = apply_filters( self::FILTER_OVERRIDE_SELECTED_CURRENCY, false );

    if ( is_scalar( $override_currency_code ) && '' !== trim( (string) $override_currency_code ) ) {
        return strtoupper( trim( (string) $override_currency_code ) );
    }

    return $this->get_stored_currency_code();
}
```

- [x] **Step 2: Run focused tests to verify pass**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2d-multi-currency-selected-currency-filters`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency selected-currency compatibility filters.
```

- [x] **Step 2: Run regression and static checks**

Run the B0-B2d multi-currency regression filter by adding the updated
`MultiCurrencyStateBuilderTest` to the B2c command.

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2d.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilder.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStateBuilderTest.php plugins/woocommerce/changelog/add-native-payments-b2d-multi-currency-selected-currency-filters
git commit -m "feat(payments): add native payments B2d multi-currency selected currency filters"
```

Self-review:

- Store-currency compatibility filter wins over stored user/session currency.
- Enabled override currency is selected.
- Invalid override currency falls back to the default, matching WooPayments'
  selected-currency behavior.
- No compatibility classes, frontend JavaScript, WPCOM, or WooPayments client
  files are modified.
