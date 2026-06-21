# Core Native Payments B2ao Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments multi-currency compatibility for WooCommerce
FedEx shipping calculations in the Core-native multi-currency runtime.

**Architecture:** Add a focused FedEx compatibility projection service and
controller. The controller registers the preserved product-price conversion and
store-currency filters only when Core owns multi-currency and FedEx is
available; callbacks use WooPayments-compatible backtrace guards for FedEx
shipping calculation contexts.

**Tech Stack:** WooCommerce Core PHP, WordPress hooks, WooCommerce FedEx hook
surface, native multi-currency decision filters, PHPUnit, PHPStan, PHPCS,
markdownlint.

---

## File Structure

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyFedExCompatibilityProjectionService.php`
    - Pure FedEx hook manifest and conversion/store-currency predicates.
- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFedExCompatibilityController.php`
    - Runtime-gated FedEx hook registration and backtrace checks.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyFedExCompatibilityProjectionServiceTest.php`
    - Pure manifest and predicate coverage.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFedExCompatibilityControllerTest.php`
    - Hook registration gates, callback behavior, and bootstrap coverage.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add a `fedex_compatibility` hook group to the native manifest.
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the FedEx compatibility group and preserved hook names.
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the FedEx compatibility controller near the other carrier
      compatibility controllers.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2ao-fedex-compatibility`
    - Patch changelog entry for FedEx shipping compatibility.

## Task 1: RED Projection And Registry Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyFedExCompatibilityProjectionServiceTest.php`
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Add projection tests**

Create `MultiCurrencyFedExCompatibilityProjectionServiceTest` covering:

```php
public function test_projects_fedex_compatibility_hook_manifest(): void {
	$manifest = MultiCurrencyFedExCompatibilityProjectionService::get_hook_manifest();

	$this->assertSame(
		array(
			'wcpay_multi_currency_should_convert_product_price',
			'wcpay_multi_currency_should_return_store_currency',
		),
		array_column( $manifest['filters'], 'hook' )
	);
	$this->assertSame( array(), $manifest['actions'] );
	$this->assertSame( 'should_convert_product_price', $manifest['filters'][0]['callback'] );
	$this->assertSame( 'should_return_store_currency', $manifest['filters'][1]['callback'] );
}

public function test_projects_fedex_conversion_decisions(): void {
	$this->assertTrue(
		MultiCurrencyFedExCompatibilityProjectionService::should_convert_product_price( true, false )
	);
	$this->assertFalse(
		MultiCurrencyFedExCompatibilityProjectionService::should_convert_product_price( true, true )
	);
	$this->assertFalse(
		MultiCurrencyFedExCompatibilityProjectionService::should_convert_product_price( false, true )
	);
	$this->assertTrue(
		MultiCurrencyFedExCompatibilityProjectionService::should_return_store_currency( true, false )
	);
	$this->assertTrue(
		MultiCurrencyFedExCompatibilityProjectionService::should_return_store_currency( false, true )
	);
	$this->assertFalse(
		MultiCurrencyFedExCompatibilityProjectionService::should_return_store_currency( false, false )
	);
}
```

Also cover `should_register( true )` true and `should_register( false )` false.

- [ ] **Step 2: Add registry expectations**

Insert `fedex_compatibility` after `ups_compatibility` and before
`subscriptions_compatibility` in the runtime registry key assertion.

Add a registry test asserting the two preserved filter hooks and empty actions:

```php
public function test_fedex_compatibility_manifest_contains_preserved_hooks(): void {
	$hook_groups  = MultiCurrencyRuntimeRegistry::get_core_hook_groups();
	$filter_hooks = array_column( $hook_groups['fedex_compatibility']['filters'], 'hook' );

	$this->assertSame(
		array(
			'wcpay_multi_currency_should_convert_product_price',
			'wcpay_multi_currency_should_return_store_currency',
		),
		$filter_hooks
	);
	$this->assertSame( array(), $hook_groups['fedex_compatibility']['actions'] );
}
```

- [ ] **Step 3: Run RED projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFedExCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyFedExCompatibilityProjectionService` and
the `fedex_compatibility` registry group do not exist yet.

## Task 2: GREEN Projection And Registry

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyFedExCompatibilityProjectionService.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Add the projection service**

Create a projection service with:

```php
private const FILTER_PREFIX = 'wcpay_multi_currency_';

public static function get_hook_manifest(): array {
	return array(
		'filters' => array(
			self::hook_entry( self::filter_name( 'should_convert_product_price' ), 'should_convert_product_price' ),
			self::hook_entry( self::filter_name( 'should_return_store_currency' ), 'should_return_store_currency' ),
		),
		'actions' => array(),
	);
}

public static function should_register( bool $fedex_available ): bool {
	return $fedex_available;
}

public static function should_convert_product_price(
	bool $should_convert,
	bool $is_fedex_shipping_context
): bool {
	return $should_convert && ! $is_fedex_shipping_context;
}

public static function should_return_store_currency(
	bool $should_return,
	bool $is_fedex_shipping_context
): bool {
	return $should_return || $is_fedex_shipping_context;
}
```

- [ ] **Step 2: Add the registry group**

Import `MultiCurrencyFedExCompatibilityProjectionService` and add:

```php
'fedex_compatibility' => MultiCurrencyFedExCompatibilityProjectionService::get_hook_manifest(),
```

Place it after `ups_compatibility` and before `subscriptions_compatibility`.

- [ ] **Step 3: Run GREEN projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFedExCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 3: RED Controller Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyFedExCompatibilityControllerTest.php`

- [ ] **Step 1: Add controller tests**

Create tests that assert:

- Plugin-owned runtime registers no FedEx hooks.
- Core runtime without FedEx available registers no hooks.
- Core runtime before `plugins_loaded` defers registration to
  `plugins_loaded` priority 20.
- Core runtime with FedEx available registers both preserved filters once.
- `should_convert_product_price()` preserves false and returns false during
  FedEx shipping calculation backtrace contexts.
- `should_return_store_currency()` preserves true and returns true during FedEx
  shipping calculation backtrace contexts.
- WooCommerce bootstrap resolves and registers
  `MultiCurrencyFedExCompatibilityController`.

Use these FedEx backtrace calls:

```php
'WC_Shipping_Fedex->set_settings'
'WC_Shipping_Fedex->per_item_shipping'
'WC_Shipping_Fedex->box_shipping'
'WC_Shipping_Fedex->get_fedex_api_request'
'WC_Shipping_Fedex->get_fedex_requests'
'WC_Shipping_Fedex->process_result'
```

- [ ] **Step 2: Run RED controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFedExCompatibilityControllerTest|MultiCurrencyFedExCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyFedExCompatibilityController` and its
bootstrap registration do not exist yet.

## Task 4: GREEN Controller And Bootstrap

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFedExCompatibilityController.php`
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller**

Create a controller following the UPS compatibility pattern, with:

```php
private const FEDEX_SHIPPING_CALLS = array(
	'WC_Shipping_Fedex->set_settings',
	'WC_Shipping_Fedex->per_item_shipping',
	'WC_Shipping_Fedex->box_shipping',
	'WC_Shipping_Fedex->get_fedex_api_request',
	'WC_Shipping_Fedex->get_fedex_requests',
	'WC_Shipping_Fedex->process_result',
);
```

Register filters from the projection manifest and implement:

```php
public function should_convert_product_price( bool $should_convert ): bool
public function should_return_store_currency( bool $should_return ): bool
protected function is_fedex_runtime_available(): bool
```

Use `class_exists( 'WC_Shipping_Fedex_Init' )` for runtime availability and
`wp_debug_backtrace_summary( null, 0, false )` for the compatibility backtrace
guard.

- [ ] **Step 2: Register the controller in WooCommerce bootstrap**

In `plugins/woocommerce/includes/class-woocommerce.php`, add:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFedExCompatibilityController::class )->register();
```

Place it near the other carrier compatibility controllers.

- [ ] **Step 3: Run GREEN controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFedExCompatibilityControllerTest|MultiCurrencyFedExCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 5: Verification, Changelog, And Commits

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2ao-fedex-compatibility`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix

Preserve native multi-currency store-currency handling for WooCommerce FedEx rates.
```

- [ ] **Step 2: Run final gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFedExCompatibilityControllerTest|MultiCurrencyFedExCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyUpsCompatibilityControllerTest|MultiCurrencyPreOrdersCompatibilityControllerTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyFedExCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyFedExCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyFedExCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyFedExCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php tests/php/src/Internal/MultiCurrency/MultiCurrencyFedExCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyFedExCompatibilityProjectionServiceTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint --fix .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ao.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ao.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
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
git commit -m "fix(payments): add FedEx multi-currency compatibility"
```

- [ ] **Step 4: Commit changelog**

Stage only the changelog entry and commit:

```bash
git commit -m "chore(payments): add FedEx compatibility changelog"
```

- [ ] **Step 5: Log git range**

Use the previous commit before this slice as the range start:

```text
24f76f638df7a2ed09e3a006eb86ec3264c2b563...<b2ao-changelog-commit>
```

Record the range and gate evidence in both logs. Keep WPCOM read-only and do
not commit or push anything in WPCOM.
