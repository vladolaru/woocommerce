# Core Native Payments B2ap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments multi-currency compatibility for WooCommerce
Points and Rewards earn/redeem ratios in the Core-native multi-currency
runtime.

**Architecture:** Add a focused Points and Rewards compatibility projection
service and controller. The controller registers the preserved option filters
only when Core owns multi-currency, Points and Rewards is available, and the
request is frontend; ratio conversion reads native multi-currency state and
multiplies the monetary value by the selected currency rate.

**Tech Stack:** WooCommerce Core PHP, WordPress option filters, WooCommerce
Points and Rewards option surface, native multi-currency state services,
PHPUnit, PHPStan, PHPCS, markdownlint.

---

## File Structure

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPointsRewardsCompatibilityProjectionService.php`
    - Pure hook manifest, registration predicate, and ratio conversion helpers.
- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityController.php`
    - Runtime-gated hook registration, native state-builder access, and
      WooPayments-compatible discount backtrace guard.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPointsRewardsCompatibilityProjectionServiceTest.php`
    - Manifest, registration, and ratio conversion coverage.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityControllerTest.php`
    - Hook registration gates, conversion behavior, backtrace skip behavior,
      and bootstrap coverage.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add a `points_rewards_compatibility` hook group.
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the Points and Rewards group and preserved option filters.
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the Points and Rewards compatibility controller near the other
      compatibility controllers.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2ap-points-rewards-compatibility`
    - Patch changelog entry for Points and Rewards ratio compatibility.

## Task 1: RED Projection And Registry Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPointsRewardsCompatibilityProjectionServiceTest.php`
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Add projection tests**

Create tests asserting:

```php
$manifest = MultiCurrencyPointsRewardsCompatibilityProjectionService::get_hook_manifest();
$this->assertSame(
	array(
		'option_wc_points_rewards_earn_points_ratio',
		'option_wc_points_rewards_redeem_points_ratio',
	),
	array_column( $manifest['filters'], 'hook' )
);
$this->assertSame( 'convert_points_ratio', $manifest['filters'][0]['callback'] );
$this->assertSame( 50, $manifest['filters'][0]['priority'] );
$this->assertSame( 1, $manifest['filters'][0]['accepted_args'] );
```

Also cover:

- `should_register( true, false )` is true.
- `should_register( true, true )` is false.
- `should_register( false, false )` is false.
- `should_convert_ratio( false, false )` is false.
- `should_convert_ratio( true, true )` is false.
- `should_convert_ratio( true, false )` is true.
- `convert_ratio_value( '10:2', 0.8 )` returns `10:1.6`.
- `convert_ratio_value( '', 0.8 )` returns `0:0`.
- `convert_ratio_value( 'bad', 0.8 )` returns `0:0`.

- [ ] **Step 2: Add registry expectations**

Insert `points_rewards_compatibility` after `fedex_compatibility` and before
`subscriptions_compatibility` in the runtime registry key assertion.

Add a registry test asserting the two option filter hooks and empty actions.

- [ ] **Step 3: Run RED projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPointsRewardsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because the projection service and runtime group do not exist.

## Task 2: GREEN Projection And Registry

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPointsRewardsCompatibilityProjectionService.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Add the projection service**

Create a projection service with:

```php
public static function should_register( bool $points_rewards_available, bool $is_admin ): bool {
	return $points_rewards_available && ! $is_admin;
}

public static function should_convert_ratio(
	bool $selected_and_default_currency_differ,
	bool $is_discount_data_context
): bool {
	return $selected_and_default_currency_differ && ! $is_discount_data_context;
}

public static function convert_ratio_value( string $ratio, float $selected_rate ): string {
	$parts  = explode( ':', $ratio );
	$points = (float) ( $parts[0] ?? 0 );
	$value  = (float) ( $parts[1] ?? 0 );

	return $points . ':' . ( $value * $selected_rate );
}
```

The hook manifest must include `option_wc_points_rewards_earn_points_ratio`
and `option_wc_points_rewards_redeem_points_ratio`, both callback
`convert_points_ratio`, priority 50, accepted args 1.

- [ ] **Step 2: Add the registry group**

Import `MultiCurrencyPointsRewardsCompatibilityProjectionService` and add:

```php
'points_rewards_compatibility' => MultiCurrencyPointsRewardsCompatibilityProjectionService::get_hook_manifest(),
```

- [ ] **Step 3: Run GREEN projection tests**

Run the same focused projection filter. Expected: pass.

## Task 3: RED Controller Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityControllerTest.php`

- [ ] **Step 1: Add controller tests**

Create tests asserting:

- Plugin-owned runtime registers no option filters.
- Core runtime without Points and Rewards registers no filters.
- Core admin request registers no filters.
- Core frontend request registers both option filters once.
- `convert_points_ratio( '10:2' )` returns `10:1.6` when selected GBP rate is
  `0.8` and default USD differs.
- `convert_points_ratio()` returns the original ratio when selected and default
  currencies match.
- `convert_points_ratio()` returns the original ratio during
  `WC_Points_Rewards_Discount->get_discount_data`.
- WooCommerce bootstrap resolves and registers
  `MultiCurrencyPointsRewardsCompatibilityController`.

- [ ] **Step 2: Run RED controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPointsRewardsCompatibilityControllerTest|MultiCurrencyPointsRewardsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because the controller and bootstrap registration do not exist.

## Task 4: GREEN Controller And Bootstrap

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityController.php`
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller**

Create a controller following the other compatibility controllers. It needs:

- `MultiCurrencyRuntimeArbiter`.
- Optional `MultiCurrencyStateBuilder` test seam.
- Lazy state builder from `MultiCurrencyStateBuilderFactory`.
- `is_points_rewards_runtime_available()` using
  `class_exists( 'WC_Points_Rewards' )`.
- `is_admin_request()` using `is_admin()`.
- `is_call_in_backtrace()` matching
  `WC_Points_Rewards_Discount->get_discount_data`.
- `convert_points_ratio( string $ratio = '' ): string`.

- [ ] **Step 2: Register the controller in WooCommerce bootstrap**

Add:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyPointsRewardsCompatibilityController::class )->register();
```

- [ ] **Step 3: Run GREEN controller tests**

Run the same focused controller filter. Expected: pass.

## Task 5: Verification, Changelog, And Commits

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2ap-points-rewards-compatibility`
- Update the scratchpad and external staging log.

- [ ] **Step 1: Add changelog**

```text
Significance: patch
Type: fix

Preserve native multi-currency conversion for WooCommerce Points and Rewards ratios.
```

- [ ] **Step 2: Run final gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPointsRewardsCompatibilityControllerTest|MultiCurrencyPointsRewardsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyFedExCompatibilityControllerTest|MultiCurrencyUpsCompatibilityControllerTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyPointsRewardsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyPointsRewardsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php tests/php/src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPointsRewardsCompatibilityProjectionServiceTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint --fix .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ap.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2ap.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Commit source/tests**

Run staged gates, then commit:

```bash
git commit -m "fix(payments): add points rewards multi-currency compatibility"
```

- [ ] **Step 4: Commit changelog**

```bash
git commit -m "chore(payments): add points rewards compatibility changelog"
```

- [ ] **Step 5: Log git range**

Use range start:

```text
a26d1cab14d66e9abc978e64eb3d795467fe0ce6...<b2ap-changelog-commit>
```
