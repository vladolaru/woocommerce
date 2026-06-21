# Core Native Payments B2al Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments multi-currency compatibility for WooCommerce
Bookings in the Core-native multi-currency runtime.

**Architecture:** Add a focused Bookings compatibility projection service and
controller instead of expanding the base compatibility controller. The
controller registers WooPayments-compatible Bookings hooks only when Core owns
multi-currency, Bookings is available, and the request is frontend or Bookings
Ajax; callbacks delegate price math to the native price projection service and
currency formatting to the native frontend projection service.

**Tech Stack:** WooCommerce Core PHP, WordPress hooks, WooCommerce Bookings
hook surface, native multi-currency services, PHPUnit, PHPStan, PHPCS,
markdownlint.

---

## File Structure

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyBookingsCompatibilityProjectionService.php`
    - Pure Bookings hook manifest and guard decisions.
    - Conversion decisions for calculated costs, booking price properties,
      resource price arrays, and Bookings product-price display suppression.
- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php`
    - Runtime-gated Bookings hook registration.
    - Lazy native price/frontend projection services.
    - Backtrace checks matching WooPayments `wp_debug_backtrace_summary()`
      strings.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyBookingsCompatibilityProjectionServiceTest.php`
    - Pure manifest and decision coverage.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityControllerTest.php`
    - Hook registration gates, price conversion behavior, Ajax formatting, and
      bootstrap coverage.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add a `bookings_compatibility` hook group to the native manifest.
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the Bookings compatibility group and preserved hook names.
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the Bookings compatibility controller after the base
      compatibility controller and before Subscriptions compatibility.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2al-bookings-compatibility`
    - Patch changelog entry for the Bookings multi-currency compatibility fix.

## Task 1: RED Projection And Registry Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyBookingsCompatibilityProjectionServiceTest.php`
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Add pure projection tests**

Create `MultiCurrencyBookingsCompatibilityProjectionServiceTest` with these
behaviors:

```php
public function test_projects_bookings_compatibility_hook_manifest(): void {
	$manifest = MultiCurrencyBookingsCompatibilityProjectionService::get_hook_manifest();

	$this->assertSame(
		array(
			'woocommerce_bookings_calculated_booking_cost',
			'woocommerce_product_get_block_cost',
			'woocommerce_product_get_cost',
			'woocommerce_product_get_display_cost',
			'woocommerce_product_booking_person_type_get_block_cost',
			'woocommerce_product_booking_person_type_get_cost',
			'woocommerce_product_get_resource_base_costs',
			'woocommerce_product_get_resource_block_costs',
			'wcpay_multi_currency_should_convert_product_price',
			'woocommerce_bookings_process_cost_rules_cost',
			'woocommerce_bookings_process_cost_rules_base_cost',
		),
		array_column( $manifest['filters'], 'hook' )
	);
	$this->assertSame(
		array(
			'wp_ajax_wc_bookings_calculate_costs',
			'wp_ajax_nopriv_wc_bookings_calculate_costs',
		),
		array_column( $manifest['actions'], 'hook' )
	);
}
```

Also cover:

- `should_register( true, false, false )` is true.
- `should_register( true, true, true )` is true.
- `should_register( true, true, false )` is false.
- `should_register( false, false, false )` is false.
- `should_adjust_calculated_booking_cost( true )` is false.
- `get_booking_price_type( true )` returns `product`.
- `get_booking_price_type( false )` returns `exchange_rate`.
- `should_convert_booking_price( '10.00', false )` is true.
- `should_convert_booking_price( 0, false )` is false.
- `should_convert_booking_product_price( true, 'booking', true )` is false.
- `should_convert_booking_product_price( true, 'booking', false )` is true.
- `should_convert_booking_product_price( true, 'simple', true )` is true.

- [ ] **Step 2: Add registry expectations**

In `MultiCurrencyRuntimeRegistryTest::test_exposes_core_hook_groups_when_core_owns_runtime()`,
insert `bookings_compatibility` after `compatibility` and before
`subscriptions_compatibility`.

Add:

```php
public function test_bookings_compatibility_manifest_contains_preserved_hooks(): void {
	$hook_groups  = MultiCurrencyRuntimeRegistry::get_core_hook_groups();
	$filter_hooks = array_column( $hook_groups['bookings_compatibility']['filters'], 'hook' );
	$action_hooks = array_column( $hook_groups['bookings_compatibility']['actions'], 'hook' );

	$this->assertSame(
		array(
			'woocommerce_bookings_calculated_booking_cost',
			'woocommerce_product_get_block_cost',
			'woocommerce_product_get_cost',
			'woocommerce_product_get_display_cost',
			'woocommerce_product_booking_person_type_get_block_cost',
			'woocommerce_product_booking_person_type_get_cost',
			'woocommerce_product_get_resource_base_costs',
			'woocommerce_product_get_resource_block_costs',
			'wcpay_multi_currency_should_convert_product_price',
			'woocommerce_bookings_process_cost_rules_cost',
			'woocommerce_bookings_process_cost_rules_base_cost',
		),
		$filter_hooks
	);
	$this->assertSame(
		array(
			'wp_ajax_wc_bookings_calculate_costs',
			'wp_ajax_nopriv_wc_bookings_calculate_costs',
		),
		$action_hooks
	);
}
```

- [ ] **Step 3: Run RED projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyBookingsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyBookingsCompatibilityProjectionService`
and the `bookings_compatibility` registry group do not exist yet.

## Task 2: GREEN Projection And Registry

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyBookingsCompatibilityProjectionService.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Add the projection service**

Create the service with:

```php
class MultiCurrencyBookingsCompatibilityProjectionService {
	private const FILTER_PREFIX = 'wcpay_multi_currency_';

	public static function get_hook_manifest(): array {
		return array(
			'actions' => array(
				array(
					'hook'          => 'wp_ajax_wc_bookings_calculate_costs',
					'callback'      => 'add_wc_price_args_filter_for_ajax',
					'priority'      => 9,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'wp_ajax_nopriv_wc_bookings_calculate_costs',
					'callback'      => 'add_wc_price_args_filter_for_ajax',
					'priority'      => 9,
					'accepted_args' => 1,
				),
			),
			'filters' => array(
				// Include the filters listed in Task 1, all at priority 50.
			),
		);
	}

	public static function should_register( bool $bookings_available, bool $is_admin, bool $is_ajax ): bool {
		return $bookings_available && ( ! $is_admin || $is_ajax );
	}

	public static function should_adjust_calculated_booking_cost( bool $is_cart_add_to_cart_context ): bool {
		return ! $is_cart_add_to_cart_context;
	}

	public static function get_booking_price_type( bool $is_price_html_context ): string {
		return $is_price_html_context ? 'product' : 'exchange_rate';
	}

	public static function should_convert_booking_price( $price, bool $is_cart_add_to_cart_cost_calculation_context ): bool {
		return (bool) $price && ! $is_cart_add_to_cart_cost_calculation_context;
	}

	public static function should_convert_booking_product_price( bool $should_convert, string $product_type, bool $is_price_html_context ): bool {
		return $should_convert && 'booking' === $product_type && $is_price_html_context
			? false
			: $should_convert;
	}
}
```

The actual filter manifest must include concrete callback names and
`accepted_args`; do not leave the placeholder comment in the committed code.

- [ ] **Step 2: Add the registry group**

In `MultiCurrencyRuntimeRegistry::get_core_hook_groups()`, add
`bookings_compatibility` with the projection service manifest.

- [ ] **Step 3: Run GREEN projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyBookingsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 3: RED Controller Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityControllerTest.php`

- [ ] **Step 1: Add controller registration tests**

Create tests that assert:

- Plugin-owned runtime registers no Bookings hooks.
- Core runtime without Bookings available registers no hooks.
- Core admin non-Ajax request registers no hooks.
- Core frontend request with Bookings available registers all manifest hooks
  once at WooPayments priority.
- Core before `plugins_loaded` defers to `plugins_loaded` priority 20 and then
  registers when Bookings becomes available.

- [ ] **Step 2: Add conversion behavior tests**

Use injected native projection services and a controller test double with
deterministic backtrace state. Cover:

- Calculated booking cost converts through product-style projection unless
  `WC_Cart->add_to_cart` is in the backtrace.
- Bookings price hooks convert with `exchange_rate` in calculation contexts and
  with `product` when `WC_Product_Booking->get_price_html` is in the backtrace.
- Price hooks return falsey values unchanged.
- Resource price arrays convert each numeric entry and non-arrays pass through.
- Booking product price conversion is suppressed only for booking products in
  price HTML context.
- Ajax cost calculation action adds the `wc_price_args` filter and the filter
  projects selected-currency `currency`, separators, decimals, and price format.

- [ ] **Step 3: Add bootstrap test**

Assert the DI container can resolve
`MultiCurrencyBookingsCompatibilityController` and
`includes/class-woocommerce.php` contains the controller registration line.

- [ ] **Step 4: Run RED controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyBookingsCompatibilityControllerTest|MultiCurrencyBookingsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyBookingsCompatibilityController` and the
bootstrap registration do not exist yet.

## Task 4: GREEN Controller

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php`
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller**

Implement:

- `init( MultiCurrencyRuntimeArbiter $arbiter )`
- `set_price_projection_service()` and `set_frontend_projection_service()`
  test seams
- `register()` and `register_bookings_filters()`
- Public hook callbacks:
  `adjust_amount_for_calculated_booking_cost()`, `get_price()`,
  `get_resource_prices()`, `should_convert_product_price()`,
  `add_wc_price_args_filter_for_ajax()`, and `filter_wc_price_args()`
- Protected runtime/backtrace helpers for tests:
  `is_bookings_runtime_available()`, `is_admin_request()`,
  `is_ajax_request()`, `have_plugins_loaded()`, and `is_call_in_backtrace()`

- [ ] **Step 2: Register from WooCommerce bootstrap**

Add:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBookingsCompatibilityController::class )->register();
```

after `MultiCurrencyCompatibilityController` and before
`MultiCurrencySubscriptionsCompatibilityController`.

- [ ] **Step 3: Run GREEN controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyBookingsCompatibilityControllerTest|MultiCurrencyBookingsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 5: Changelog, Gates, And Commit

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2al-bookings-compatibility`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

Create a patch changelog entry:

```text
Significance: patch
Type: fix

Preserve native multi-currency compatibility for WooCommerce Bookings prices.
```

- [ ] **Step 2: Run final verification**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyBookingsCompatibilityControllerTest|MultiCurrencyBookingsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyCompatibilityControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyFrontendPricesControllerTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyBookingsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2al.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

Expected: all pass.

- [ ] **Step 3: Stage only B2al files**

Stage source, tests, bootstrap, changelog, and the two logs. Do not stage
untracked historical plan docs.

- [ ] **Step 4: Run staged checks**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
git diff --cached --check
```

Expected: both pass.

- [ ] **Step 5: Commit**

Commit source/tests and changelog as separate logical commits:

```bash
git commit -m "fix(payments): add bookings multi-currency compatibility"
git commit -m "chore(payments): add bookings compatibility changelog"
```

Record the git range in both logs.

## Self-Review

- Spec coverage: B2al covers one remaining Bucket C adapter,
  WooCommerce Bookings, without mixing in Deposits, Product Add-ons,
  Pre-Orders, Name Your Price, shipping extension, or Points and Rewards
  adapters.
- Placeholder scan: the only placeholder-like note is inside the plan's
  projection-service sketch and explicitly says not to leave it in committed
  code.
- Type consistency: controller callbacks match the projected manifest callback
  names; projected hook group name is `bookings_compatibility` in both runtime
  registry and tests.
