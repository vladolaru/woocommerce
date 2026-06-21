# Core Native Payments B2an Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments multi-currency compatibility for WooCommerce UPS
shipping calculations in the Core-native multi-currency runtime.

**Architecture:** Add a focused UPS compatibility projection service and
controller. The controller registers the preserved
`wcpay_multi_currency_should_return_store_currency` filter only when Core owns
multi-currency and the UPS runtime is available, and returns store currency
while UPS shipping calculation methods are on the call stack.

**Tech Stack:** WooCommerce Core PHP, WordPress hooks, WooCommerce UPS hook
surface, native multi-currency state filters, PHPUnit, PHPStan, PHPCS,
markdownlint.

---

## File Structure

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyUpsCompatibilityProjectionService.php`
    - Pure UPS hook manifest and store-currency decision predicate.
- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyUpsCompatibilityController.php`
    - Runtime-gated UPS hook registration.
    - Backtrace checks matching the WooPayments UPS compatibility adapter.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyUpsCompatibilityProjectionServiceTest.php`
    - Pure manifest and predicate coverage.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyUpsCompatibilityControllerTest.php`
    - Hook registration gates, backtrace behavior, and bootstrap coverage.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add an `ups_compatibility` hook group to the native manifest.
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the UPS compatibility group and preserved hook name.
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the UPS compatibility controller near the other compatibility
      controllers.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2an-ups-compatibility`
    - Patch changelog entry for UPS shipping compatibility.

## Task 1: RED Projection And Registry Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyUpsCompatibilityProjectionServiceTest.php`
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Add projection tests**

Create `MultiCurrencyUpsCompatibilityProjectionServiceTest`:

```php
public function test_projects_ups_compatibility_hook_manifest(): void {
	$manifest = MultiCurrencyUpsCompatibilityProjectionService::get_hook_manifest();

	$this->assertSame(
		array( 'wcpay_multi_currency_should_return_store_currency' ),
		array_column( $manifest['filters'], 'hook' )
	);
	$this->assertSame( array(), $manifest['actions'] );
	$this->assertSame( 'should_return_store_currency', $manifest['filters'][0]['callback'] );
	$this->assertSame( 10, $manifest['filters'][0]['priority'] );
	$this->assertSame( 1, $manifest['filters'][0]['accepted_args'] );
}

public function test_requires_ups_runtime_before_registering_hooks(): void {
	$this->assertTrue( MultiCurrencyUpsCompatibilityProjectionService::should_register( true ) );
	$this->assertFalse( MultiCurrencyUpsCompatibilityProjectionService::should_register( false ) );
}

public function test_projects_store_currency_decision_for_ups_context(): void {
	$this->assertTrue(
		MultiCurrencyUpsCompatibilityProjectionService::should_return_store_currency( true, false )
	);
	$this->assertTrue(
		MultiCurrencyUpsCompatibilityProjectionService::should_return_store_currency( false, true )
	);
	$this->assertFalse(
		MultiCurrencyUpsCompatibilityProjectionService::should_return_store_currency( false, false )
	);
}
```

- [ ] **Step 2: Add registry expectations**

In
`MultiCurrencyRuntimeRegistryTest::test_exposes_core_hook_groups_when_core_owns_runtime()`,
insert `ups_compatibility` after `pre_orders_compatibility` and before
`subscriptions_compatibility`.

Add:

```php
public function test_ups_compatibility_manifest_contains_preserved_hooks(): void {
	$hook_groups  = MultiCurrencyRuntimeRegistry::get_core_hook_groups();
	$filter_hooks = array_column( $hook_groups['ups_compatibility']['filters'], 'hook' );

	$this->assertSame(
		array( 'wcpay_multi_currency_should_return_store_currency' ),
		$filter_hooks
	);
	$this->assertSame( array(), $hook_groups['ups_compatibility']['actions'] );
}
```

- [ ] **Step 3: Run RED projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyUpsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyUpsCompatibilityProjectionService` and
the `ups_compatibility` registry group do not exist yet.

## Task 2: GREEN Projection And Registry

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyUpsCompatibilityProjectionService.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Add the projection service**

Create a projection service that exposes:

```php
private const FILTER_PREFIX = 'wcpay_multi_currency_';

public static function get_hook_manifest(): array {
	return array(
		'filters' => array(
			array(
				'hook'          => self::filter_name( 'should_return_store_currency' ),
				'callback'      => 'should_return_store_currency',
				'priority'      => 10,
				'accepted_args' => 1,
			),
		),
		'actions' => array(),
	);
}

public static function should_register( bool $ups_available ): bool {
	return $ups_available;
}

public static function should_return_store_currency(
	bool $should_return,
	bool $is_ups_shipping_context
): bool {
	return $should_return || $is_ups_shipping_context;
}
```

- [ ] **Step 2: Add the registry group**

Import `MultiCurrencyUpsCompatibilityProjectionService` and add:

```php
'ups_compatibility' => MultiCurrencyUpsCompatibilityProjectionService::get_hook_manifest(),
```

Place it after `pre_orders_compatibility` and before
`subscriptions_compatibility`.

- [ ] **Step 3: Run GREEN projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyUpsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 3: RED Controller Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyUpsCompatibilityControllerTest.php`

- [ ] **Step 1: Add controller tests**

Create tests that assert:

- Plugin-owned runtime registers no UPS hooks.
- Core runtime without UPS available registers no hooks.
- Core runtime before `plugins_loaded` defers registration to
  `plugins_loaded` priority 20.
- Core runtime with UPS available registers
  `wcpay_multi_currency_should_return_store_currency` once.
- `should_return_store_currency()` returns true when already true.
- `should_return_store_currency()` returns true when one of these calls is in
  the deterministic backtrace: `WC_Shipping_UPS->per_item_shipping`,
  `WC_Shipping_UPS->box_shipping`, `WC_Shipping_UPS->calculate_shipping`.
- `should_return_store_currency()` returns false when already false and no UPS
  call is present.
- WooCommerce bootstrap resolves and registers
  `MultiCurrencyUpsCompatibilityController`.

- [ ] **Step 2: Run RED controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyUpsCompatibilityControllerTest|MultiCurrencyUpsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyUpsCompatibilityController` and its
bootstrap registration do not exist yet.

## Task 4: GREEN Controller And Bootstrap

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyUpsCompatibilityController.php`
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller**

Create a controller following the Pre-Orders compatibility pattern, with:

```php
private const UPS_SHIPPING_CALLS = array(
	'WC_Shipping_UPS->per_item_shipping',
	'WC_Shipping_UPS->box_shipping',
	'WC_Shipping_UPS->calculate_shipping',
);

public function should_return_store_currency( bool $should_return ): bool {
	return MultiCurrencyUpsCompatibilityProjectionService::should_return_store_currency(
		$should_return,
		$this->is_call_in_backtrace( self::UPS_SHIPPING_CALLS )
	);
}

protected function is_ups_runtime_available(): bool {
	return class_exists( 'WC_Shipping_UPS_Init' );
}
```

The committed class must include runtime-arbiter gating, `plugins_loaded`
defer behavior, manifest-driven filter registration, `add_filter_once()`,
`add_action_once()`, and a protected `is_call_in_backtrace()` helper using
`wp_debug_backtrace_summary( null, 0, false )`.

- [ ] **Step 2: Register the controller in WooCommerce bootstrap**

In `plugins/woocommerce/includes/class-woocommerce.php`, add:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyUpsCompatibilityController::class )->register();
```

Place it near the other multi-currency compatibility controllers.

- [ ] **Step 3: Run GREEN controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyUpsCompatibilityControllerTest|MultiCurrencyUpsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 5: Verification, Changelog, And Commits

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2an-ups-compatibility`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix

Preserve native multi-currency store-currency handling for WooCommerce UPS rates.
```

- [ ] **Step 2: Run final gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyUpsCompatibilityControllerTest|MultiCurrencyUpsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyPreOrdersCompatibilityControllerTest|MultiCurrencyBookingsCompatibilityControllerTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyUpsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyUpsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyUpsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyUpsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php tests/php/src/Internal/MultiCurrency/MultiCurrencyUpsCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyUpsCompatibilityProjectionServiceTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint --fix .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2an.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2an.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

Expected: all commands pass.

- [ ] **Step 3: Commit source/tests**

Stage only source/test/bootstrap/registry files, not changelog or plan docs.
Run staged gates:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
git diff --cached --check
```

Commit:

```bash
git commit -m "fix(payments): add UPS multi-currency compatibility"
```

- [ ] **Step 4: Commit changelog**

Stage only the changelog entry and commit:

```bash
git commit -m "chore(payments): add UPS compatibility changelog"
```

- [ ] **Step 5: Log git range**

Use the previous commit before this slice as the range start:

```text
06dd42c098367fd8b0c820d28b37ad0e1749a268...<b2an-changelog-commit>
```

Record the range and gate evidence in both logs. Keep WPCOM read-only and do
not commit or push anything in WPCOM.
