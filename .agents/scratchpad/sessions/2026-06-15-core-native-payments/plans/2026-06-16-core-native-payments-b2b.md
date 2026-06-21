# Core Native Payments B2b Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register native multi-currency frontend currency/format hooks only
when core owns multi-currency.

**Architecture:** Add one `RegisterHooksInterface` controller under
`src/Internal/MultiCurrency/`. The controller consumes
`MultiCurrencyRuntimeArbiter` and `MultiCurrencyFrontendProjectionService`,
registers the preserved WooPayments frontend currency/format/cart-hash hooks in
core-owner mode, and registers nothing in plugin/no-owner modes. Price
conversion hooks and frontend client build migration stay out of this slice.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Frontend Currency Controller Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php`

- [x] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

$sut->register();

$this->assertFalse( has_filter( 'woocommerce_currency', array( $sut, 'get_woocommerce_currency' ) ) );
$this->assertFalse( has_filter( 'woocommerce_cart_hash', array( $sut, 'add_currency_to_cart_hash' ) ) );
```

Also specify:

- core-owner mode registers:
  `woocommerce_currency`, `wc_get_price_decimals`,
  `wc_get_price_decimal_separator`, `wc_get_price_thousand_separator`,
  `woocommerce_price_format`, `option_woocommerce_currency_pos`,
  `woocommerce_cart_hash`, `woocommerce_order_get_total`,
  `woocommerce_get_formatted_order_total`,
  `woocommerce_shipping_method_add_rate_args`, `before_woocommerce_pay`,
  `woocommerce_thankyou_order_id`, and
  `woocommerce_account_view-order_endpoint`.
- registered priorities match the B2a manifest: currency/format/cart-hash
  filters at priority 900, account view order action at priority 9, and the
  remaining order-context hooks at WooPayments-compatible defaults.
- public callbacks delegate selected currency, decimals, separators, price
  format, currency position, and cart hash to
  `MultiCurrencyFrontendProjectionService`.
- order-context callbacks are safe pass-throughs until B2 price/order-context
  work fills them in.
- repeated `register()` calls do not duplicate hook callbacks.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendCurrenciesControllerTest
```

Expected: fail because `MultiCurrencyFrontendCurrenciesController` does not
exist.

## Task 2: Implement Frontend Currency Controller

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyFrontendCurrenciesController` with:

```php
final public function init(
    MultiCurrencyRuntimeArbiter $arbiter
): void {}

public function set_frontend_projection_service(
    MultiCurrencyFrontendProjectionService $frontend_projection_service
): void {}

public function register() {}

public function get_woocommerce_currency(): string {}
public function get_price_decimals( $decimals ): int {}
public function get_price_decimal_separator( $separator ): string {}
public function get_price_thousand_separator( $separator ): string {}
public function get_woocommerce_price_format( $format ): string {}
public function get_woocommerce_currency_pos( $position ): string {}
public function add_currency_to_cart_hash( $hash ): string {}
```

Also add safe pass-through methods for:

```php
public function init_order_currency_from_query_vars(): void {}
public function maybe_init_order_currency_from_order_total_prop( $total, $order ) {}
public function maybe_clear_order_currency_after_formatted_order_total( $formatted_total, $order, $tax_display, $display_refunded ) {}
public function init_order_currency( $order_id ) {}
public function fix_price_decimals_for_shipping_rates( $args, $method ) {}
```

Implementation requirements:

- Use `MultiCurrencyRuntimeArbiter::should_core_register()` as the only
  registration gate.
- Use `has_filter()` / `has_action()` checks before adding each hook, so
  repeated registration stays idempotent.
- Do not register frontend price conversion hooks in this slice.
- Wire the controller into `WC::init_hooks()` next to the existing
  multi-currency shadow mode registration.

- [x] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2b-multi-currency-frontend-currencies`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency frontend currency hook controller.
```

- [x] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Run the B0-B2b multi-currency regression filter by adding
`MultiCurrencyFrontendCurrenciesControllerTest` to the B2a command.

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

The single bootstrap line in `includes/class-woocommerce.php` is checked via
the changed-line lint command below. Full-file PHPCS for that legacy file has
pre-existing warnings unrelated to this slice.

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2b.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2b-multi-currency-frontend-currencies
git commit -m "feat(payments): add native payments B2b multi-currency frontend currency hooks"
```

Self-review:

- Plugin/no-owner modes register no frontend currency hooks.
- Core-owner mode registers the preserved currency/format/cart-hash hook
  surface once.
- Callback behavior delegates to the B1 frontend projection service.
- Price conversion hooks and frontend JS migration remain untouched.
- No WPCOM or WooPayments client file is modified.
