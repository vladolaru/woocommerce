# Native Multi-Currency Compatibility Controller Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the preserved WooPayments multi-currency base compatibility behavior from WooCommerce core when the native runtime owns multi-currency.

**Architecture:** Add a runtime-gated `MultiCurrencyCompatibilityController` that owns the base compatibility decisions WooPayments exposes through `Compatibility.php`: pay-for-order switching disablement, external compatibility filter bridges, cron sales-record order-total conversion, and the projected integration list for later extension-specific class ports. Do not port the extension-specific compatibility classes or JS/admin assets in this slice.

**Tech Stack:** WooCommerce PHP, WordPress hooks, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPCS, PHPStan.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php`
    - `RegisterHooksInterface` implementation.
    - Runtime-gated registration for the compatibility `init` action and the cron-only sales-record filter.
    - Public compatibility decision methods mirroring WooPayments base `Compatibility.php`.
    - Sales-record `woocommerce_order_query` total conversion using preserved multi-currency order meta.
    - Test injection setter for `MultiCurrencyStateBuilder`.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyCompatibilityControllerTest.php`
    - Covers runtime gating, hook registration, compatibility integration projection, compatibility filter decisions, pay-for-order switch disabling, sales-record order conversion, and bootstrap registration.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller with the existing native multi-currency controllers.
- Add `plugins/woocommerce/changelog/add-native-payments-b2w-multi-currency-compatibility`
    - Patch changelog entry.

## Task 1: Compatibility Controller Red Test

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyCompatibilityControllerTest.php`

- [ ] **Step 1: Write the failing PHPUnit coverage**

Create a test class that imports `MultiCurrencyCompatibilityController`, `MultiCurrencyRuntimeArbiter`, `MultiCurrencyStateBuilder`, `MultiCurrencyLocalizationInterface`, `MultiCurrencyCurrency`, `MultiCurrencyState`, and `MultiCurrencyPriceProjectionService`.

The tests must cover these behaviors:

```php
public function test_does_not_register_hooks_when_plugin_owns_runtime(): void;
public function test_registers_compatibility_hooks_when_core_owns_runtime(): void;
public function test_registers_sales_record_filter_only_for_cron_requests(): void;
public function test_projects_compatibility_integrations_when_multiple_currencies_are_enabled(): void;
public function test_projects_no_compatibility_integrations_with_one_currency(): void;
public function test_applies_public_compatibility_decision_filters(): void;
public function test_disables_currency_switching_for_pay_for_order_and_external_filters(): void;
public function test_attach_order_modifier_adds_order_query_filter_and_returns_original_value(): void;
public function test_converts_sales_record_order_totals_to_default_currency(): void;
public function test_skips_sales_record_conversion_when_context_or_meta_do_not_match(): void;
public function test_bootstrap_registers_compatibility_controller(): void;
```

Use a fake state builder returning USD as default and GBP as the selected additional currency. Use a test-only subclass of the controller that overrides protected `is_cron_request()` and `is_call_in_backtrace()` methods so the hook and sales-record branches can be tested without defining global constants or relying on real backtraces.

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyCompatibilityControllerTest
```

Expected before production code:

```text
ERRORS!
Tests: 11
Class "Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController" not found
```

## Task 2: Runtime-Gated Compatibility Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller class**

Implement `MultiCurrencyCompatibilityController` with:

```php
private const FILTER_PREFIX = 'wcpay_multi_currency_';
private const NEW_SALES_RECORD_BACKTRACE_CALLS = array(
    'Automattic\\WooCommerce\\Admin\\Notes\\NewSalesRecord::sum_sales_for_date',
    'Automattic\\WooCommerce\\Admin\\Notes\\NewSalesRecord::possibly_add_note',
);
```

Core properties and methods:

```php
private MultiCurrencyRuntimeArbiter $arbiter;
private ?MultiCurrencyStateBuilder $state_builder = null;

/**
 * @var string[]
 */
private array $compatibility_integrations = array();

final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void;
public function set_state_builder( MultiCurrencyStateBuilder $state_builder ): void;
public function register();
public function init_compatibility_classes(): void;
public function get_compatibility_integrations(): array;
```

`register()` must return immediately unless `$this->arbiter->should_core_register()` is true. When core owns the runtime, register `init_compatibility_classes()` on `init` priority `11`. Register `attach_order_modifier()` on `woocommerce_admin_sales_record_milestone_enabled` priority `10` only when `$this->is_cron_request()` is true. Both hook registrations must be idempotent for the same controller instance.

- [ ] **Step 2: Add state and test seam helpers**

Add these helpers:

```php
private function get_state_builder(): MultiCurrencyStateBuilder;
private function add_action_once( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void;
private function add_filter_once( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void;
protected function is_cron_request(): bool;
protected function is_call_in_backtrace( array $expected_calls ): bool;
private static function filter_name( string $suffix ): string;
```

Default `get_state_builder()` should follow existing multi-currency controller patterns by creating `MultiCurrencyLocalizationService`, `CurrencyRateProviderRegistry`, `MultiCurrencyRateService`, and `MultiCurrencyDatabaseCache`.

Default `is_cron_request()` should preserve the WooPayments base behavior by checking whether `DOING_CRON` is defined.

Default `is_call_in_backtrace()` should inspect `debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS )` for entries matching the fully-qualified `Class::method` strings in `NEW_SALES_RECORD_BACKTRACE_CALLS`.

- [ ] **Step 3: Bootstrap the controller**

Add the controller to the native multi-currency bootstrap section in `plugins/woocommerce/includes/class-woocommerce.php` near the other multi-currency controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController::class )->register();
```

Place it after `MultiCurrencyAnalyticsController` and before Storefront/async/REST controllers so compatibility decisions are registered before surfaces that may need them.

## Task 3: Compatibility Decisions And Sales-Record Conversion

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php`

- [ ] **Step 1: Project compatibility integrations**

`init_compatibility_classes()` must build the current multi-currency state and store:

```php
$this->compatibility_integrations = MultiCurrencyCompatibilityProjectionService::get_compatibility_integrations(
    $this->get_state_builder()->build()->has_additional_currencies_enabled()
);
```

Do not instantiate or port the extension-specific compatibility classes in this slice. They remain a later B2/B3 migration surface.

- [ ] **Step 2: Add public compatibility decision methods**

Implement these public methods:

```php
public function override_selected_currency();
public function should_hide_widgets(): bool;
public function should_disable_currency_switching(): bool;
public function should_convert_coupon_amount( $coupon = null ): bool;
public function should_convert_product_price( $product = null ): bool;
public function should_return_store_currency(): bool;
```

Expected behavior:

- `override_selected_currency()` returns `apply_filters( 'wcpay_multi_currency_override_selected_currency', false )`.
- `should_hide_widgets()` calls `wc_deprecated_function( __FUNCTION__, '6.5.0', 'MultiCurrencyCompatibilityController::should_disable_currency_switching' )` and returns `should_disable_currency_switching()`.
- `should_disable_currency_switching()` starts false, sets true when `$_GET['pay_for_order']` exists, applies deprecated `wcpay_multi_currency_should_hide_widgets` when present after `wc_deprecated_hook()`, then applies `wcpay_multi_currency_should_disable_currency_switching`.
- `should_convert_coupon_amount()` and `should_convert_product_price()` return true when the coupon/product argument is empty, otherwise apply the preserved `wcpay_multi_currency_should_convert_*` filters.
- `should_return_store_currency()` applies `wcpay_multi_currency_should_return_store_currency` with default false.

- [ ] **Step 3: Add sales-record order conversion callbacks**

Implement:

```php
public function attach_order_modifier( $enabled );
public function convert_order_prices( $results );
```

Expected behavior:

- `attach_order_modifier()` registers `convert_order_prices()` on `woocommerce_order_query` and returns `$enabled` unchanged.
- `convert_order_prices()` returns non-array results unchanged.
- `convert_order_prices()` returns arrays unchanged unless `is_call_in_backtrace( self::NEW_SALES_RECORD_BACKTRACE_CALLS )` is true.
- For each `WC_Order` or `WC_Order_Refund` result, convert totals back to the store default currency only when:
    - the result currency differs from the default currency,
    - `_wcpay_multi_currency_order_exchange_rate` exists and is numeric,
    - `_wcpay_multi_currency_order_default_currency` matches the default currency,
    - the exchange rate is greater than zero.
- Conversion formula:

```php
$order->set_total( wc_format_decimal( (float) $order->get_total() * ( 1 / (float) $exchange_rate ), wc_get_price_decimals() ) );
```

- Remove this controller's `woocommerce_order_query` filter after a matching conversion pass, mirroring WooPayments' one-shot behavior.
- Do not save the order; conversion must affect only the filtered result objects.

## Task 4: Changelog And Verification

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2w-multi-currency-compatibility`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

```text
Significance: patch
Type: fix
Comment: Add native multi-currency compatibility hooks.
```

- [ ] **Step 2: Run focused verification**

Create a temporary PHPStan config in `$TMPDIR`:

```bash
mkdir -p "$TMPDIR"
printf '%s\n' \
  'includes:' \
  '  - /Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/phpstan.neon' \
  'parameters:' \
  '  reportUnmatchedIgnoredErrors: false' \
  > "$TMPDIR/phpstan-b2w.neon"
```

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyCompatibilityControllerTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php --configuration "$TMPDIR/phpstan-b2w.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyCompatibilityControllerTest.php
```

Run from the repo root:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2w.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage and commit only source/test/changelog**

Stage:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyCompatibilityControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2w-multi-currency-compatibility
```

Run:

```bash
git diff --cached --check
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
```

Commit source/test/bootstrap separately from the changelog if following the WooCommerce git-commit split:

```bash
git restore --staged plugins/woocommerce/changelog/add-native-payments-b2w-multi-currency-compatibility
git commit -m "fix(payments): add multi-currency compatibility hooks"
git add plugins/woocommerce/changelog/add-native-payments-b2w-multi-currency-compatibility
git commit -m "chore(payments): add multi-currency compatibility changelog"
```

Do not stage or commit `.agents/*`, `docs/superpowers/*`, or the external staging log.

## Self-Review

- Spec coverage: B2w covers the missing PHP base compatibility behavior from WooPayments `Compatibility.php` without pulling in settings/admin assets, switcher block assets, or extension-specific compatibility classes.
- Placeholder scan: No placeholder tasks remain.
- Type consistency: Controller, services, method names, filter names, hook priorities, order meta keys, and bootstrap paths match existing native multi-currency services and the WooPayments compatibility reference.
