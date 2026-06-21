# Core Native Payments B2af Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments multi-currency Subscriptions compatibility decisions in the Core-owned native runtime.

**Architecture:** Add a focused Subscriptions compatibility projection service for pure decision logic and a controller that registers the WooPayments-compatible filters only when Core owns multi-currency, the request is frontend, and WooCommerce Subscriptions or WooPayments Subscriptions is available. Existing native price, selected-currency, and switcher controllers already consume the `wcpay_multi_currency_*` filters, so this slice feeds those decision points without duplicating price conversion.

**Tech Stack:** WooCommerce Core PHP, WordPress filters, WooCommerce CRUD/order objects, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## File Structure

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php`
    - Pure hook manifest and decision methods for subscription cart/request contexts, product conversion guards, coupon conversion guards, and mixed switch-cart purchase behavior.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`
    - Runtime-gated hook registration, cart/session/request adapters, subscription currency lookup, and public hook callbacks.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php`
    - Unit tests for pure decisions and hook manifest.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php`
    - Unit tests for registration, filter callbacks, cart/request currency lock, and bootstrap registration.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add the `subscriptions_compatibility` hook group to the native registration manifest.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the new hook group and preserved hook names.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller in the existing hook-registration section.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2af-multi-currency-subscriptions-compatibility`
    - Developer changelog for the Core-native multi-currency compatibility slice.

## Task 1: RED Projection Service Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionServiceTest.php`

- [ ] **Step 1: Write failing projection tests**

```php
public function test_projects_hook_manifest_for_subscription_compatibility(): void {
	$this->assertSame(
		array(
			'actions' => array(),
			'filters' => array(
				array(
					'hook'          => 'wcpay_multi_currency_override_selected_currency',
					'callback'      => 'override_selected_currency',
					'priority'      => 50,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'wcpay_multi_currency_should_disable_currency_switching',
					'callback'      => 'should_disable_currency_switching',
					'priority'      => 50,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'wcpay_multi_currency_should_convert_product_price',
					'callback'      => 'should_convert_product_price',
					'priority'      => 50,
					'accepted_args' => 2,
				),
				array(
					'hook'          => 'wcpay_multi_currency_should_convert_coupon_amount',
					'callback'      => 'should_convert_coupon_amount',
					'priority'      => 50,
					'accepted_args' => 2,
				),
				array(
					'hook'          => 'option_woocommerce_subscriptions_multiple_purchase',
					'callback'      => 'maybe_disable_mixed_cart',
					'priority'      => 50,
					'accepted_args' => 1,
				),
			),
		),
		MultiCurrencySubscriptionsCompatibilityProjectionService::get_hook_manifest()
	);
}

public function test_projects_switching_and_conversion_decisions(): void {
	$this->assertTrue( MultiCurrencySubscriptionsCompatibilityProjectionService::should_disable_currency_switching( false, true, false ) );
	$this->assertTrue( MultiCurrencySubscriptionsCompatibilityProjectionService::should_disable_currency_switching( false, false, true ) );
	$this->assertFalse( MultiCurrencySubscriptionsCompatibilityProjectionService::should_disable_currency_switching( false, false, false ) );

	$this->assertFalse( MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_product_price( true, true, false, false, true, false ) );
	$this->assertFalse( MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_product_price( true, false, true, false, true, false ) );
	$this->assertFalse( MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_product_price( true, false, false, false, false, true ) );
	$this->assertTrue( MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_product_price( true, true, false, true, true, false ) );

	$this->assertFalse( MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_coupon_amount( true, 'recurring_percent', false, false, false ) );
	$this->assertFalse( MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_coupon_amount( true, 'renewal_fee', true, false, true ) );
	$this->assertTrue( MultiCurrencySubscriptionsCompatibilityProjectionService::should_convert_coupon_amount( true, 'fixed_cart', true, false, true ) );
	$this->assertSame( 'no', MultiCurrencySubscriptionsCompatibilityProjectionService::maybe_disable_mixed_cart( 'yes', true ) );
}
```

- [ ] **Step 2: Run projection RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityProjectionServiceTest
```

Expected: FAIL because `MultiCurrencySubscriptionsCompatibilityProjectionService` does not exist.

## Task 2: GREEN Projection Service

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php`

- [ ] **Step 1: Implement pure projection service**

```php
final class MultiCurrencySubscriptionsCompatibilityProjectionService {
	public static function get_hook_manifest(): array;
	public static function should_disable_currency_switching( bool $should_disable, bool $has_subscription_cart_context, bool $has_switch_request_context ): bool;
	public static function should_convert_product_price( bool $should_convert, bool $has_renewal_cart_item, bool $has_resubscribe_cart_item, bool $is_renewal_setup_context, bool $is_price_calculation_context, bool $is_recurring_item_context ): bool;
	public static function should_convert_coupon_amount( bool $should_convert, string $discount_type, bool $has_renewal_cart_item, bool $is_early_renewal_context, bool $is_apply_coupon_context ): bool;
	public static function maybe_disable_mixed_cart( $multiple_purchase_value, bool $has_switch_cart_item );
}
```

- [ ] **Step 2: Run projection GREEN**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySubscriptionsCompatibilityProjectionServiceTest
```

Expected: PASS.

## Task 3: RED Controller Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Write failing controller tests**

Add tests that assert:

```php
public function test_registers_subscription_filters_for_core_frontend_runtime(): void;
public function test_does_not_register_when_plugin_owns_runtime(): void;
public function test_does_not_register_when_subscriptions_runtime_is_absent(): void;
public function test_does_not_register_for_admin_or_cron_requests(): void;
public function test_overrides_selected_currency_from_subscription_cart_item(): void;
public function test_preserves_existing_selected_currency_override(): void;
public function test_disables_switching_for_subscription_cart_or_verified_switch_request(): void;
public function test_applies_subscription_product_conversion_decisions(): void;
public function test_applies_subscription_coupon_conversion_decisions(): void;
public function test_disables_mixed_purchase_for_switch_cart_item(): void;
public function test_bootstrap_registers_subscriptions_compatibility_controller(): void;
```

Update the runtime registry test so `array_keys( $manifest['hook_groups'] )` includes `subscriptions_compatibility` immediately after `compatibility`, and add a focused assertion for the subscription hook names.

- [ ] **Step 2: Run controller RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: FAIL because the controller, runtime group, and bootstrap registration do not exist.

## Task 4: GREEN Controller And Runtime Wiring

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Implement the controller**

Register these filters at priority 50 only when:

```php
$this->arbiter->should_core_register()
&& ! $this->is_admin_request()
&& ! $this->is_cron_request()
&& $this->is_subscriptions_runtime_available()
```

Use protected adapters for cart lookup, switch request validation, backtrace checks, current user ID, and subscription object lookup so tests can simulate WooPayments Subscriptions contexts without loading the extension.

- [ ] **Step 2: Wire runtime registry and bootstrap**

Add:

```php
'subscriptions_compatibility' => MultiCurrencySubscriptionsCompatibilityProjectionService::get_hook_manifest(),
```

to `MultiCurrencyRuntimeRegistry::get_core_hook_groups()` after the base `compatibility` group. Add:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySubscriptionsCompatibilityController::class )->register();
```

after `MultiCurrencyCompatibilityController` in `includes/class-woocommerce.php`.

- [ ] **Step 3: Run controller GREEN**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: PASS.

## Task 5: Verification, Changelog, Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2af-multi-currency-subscriptions-compatibility`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Run focused regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityProjectionServiceTest|MultiCurrencyCompatibilityControllerTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyStorefrontIntegrationControllerTest|MultiCurrencySwitcherWidgetControllerTest|MultiCurrencySwitcherBlockControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: PASS.

- [ ] **Step 2: Run static checks**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencySubscriptionsCompatibilityProjectionService.php --memory-limit=2G
git diff --check
```

Expected: all commands pass.

- [ ] **Step 3: Add changelog and commit**

Create a changelog entry with significance `patch`, type `add`, and message:

```text
Add native multi-currency Subscriptions compatibility guards for renewal, resubscribe, and switch flows.
```

Commit source and changelog separately if the branch convention from the current B2 series still uses paired commits.

## Self-Review

- Spec coverage: this plan covers Subscriptions currency lock, switcher disablement, product double-conversion guards, coupon conversion guards, hook manifest visibility, and WooCommerce bootstrap. It intentionally does not cover direct subscription sign-up fee conversion, My Account formatted subscription totals, or the admin multi-currency settings bundle.
- Placeholder scan: no `TBD`, `TODO`, or unspecified "appropriate" behavior remains.
- Type consistency: production class names, method names, hook names, and file paths match the Core multi-currency naming already used in the codebase.
