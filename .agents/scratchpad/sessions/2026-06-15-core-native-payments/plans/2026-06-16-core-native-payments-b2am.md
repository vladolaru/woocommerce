# Core Native Payments B2am Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments multi-currency compatibility for WooCommerce
Pre-Orders fees in the Core-native multi-currency runtime.

**Architecture:** Add a narrow Pre-Orders compatibility projection service and
controller. The controller registers the preserved `wc_pre_orders_fee` filter
only when Core owns multi-currency and the Pre-Orders runtime is available, and
delegates fee amount conversion to the native price projection service.

**Tech Stack:** WooCommerce Core PHP, WordPress hooks, WooCommerce Pre-Orders
hook surface, native multi-currency services, PHPUnit, PHPStan, PHPCS,
markdownlint.

---

## File Structure

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPreOrdersCompatibilityProjectionService.php`
    - Pure Pre-Orders hook manifest and registration/conversion predicates.
- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php`
    - Runtime-gated Pre-Orders hook registration.
    - Lazy native price projection service.
    - `wc_pre_orders_fee` callback that converts `amount` as a product price.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPreOrdersCompatibilityProjectionServiceTest.php`
    - Pure manifest and predicate coverage.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityControllerTest.php`
    - Hook registration gates, fee amount conversion, and bootstrap coverage.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add a `pre_orders_compatibility` hook group to the native manifest.
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the Pre-Orders compatibility group and preserved hook name.
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the Pre-Orders compatibility controller near the other
      compatibility controllers.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2am-pre-orders-compatibility`
    - Patch changelog entry for Pre-Orders fee compatibility.

## Task 1: RED Projection And Registry Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPreOrdersCompatibilityProjectionServiceTest.php`
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Add pure projection tests**

Create `MultiCurrencyPreOrdersCompatibilityProjectionServiceTest`:

```php
<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPreOrdersCompatibilityProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyPreOrdersCompatibilityProjectionService class.
 */
class MultiCurrencyPreOrdersCompatibilityProjectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project Pre-Orders compatibility hook manifest.
	 */
	public function test_projects_pre_orders_compatibility_hook_manifest(): void {
		$manifest = MultiCurrencyPreOrdersCompatibilityProjectionService::get_hook_manifest();

		$this->assertSame(
			array( 'wc_pre_orders_fee' ),
			array_column( $manifest['filters'], 'hook' )
		);
		$this->assertSame( array(), $manifest['actions'] );
		$this->assertSame( 'convert_pre_orders_fee', $manifest['filters'][0]['callback'] );
		$this->assertSame( 10, $manifest['filters'][0]['priority'] );
		$this->assertSame( 1, $manifest['filters'][0]['accepted_args'] );
	}

	/**
	 * @testdox Should require Pre-Orders runtime before registering hooks.
	 */
	public function test_requires_pre_orders_runtime_before_registering_hooks(): void {
		$this->assertTrue( MultiCurrencyPreOrdersCompatibilityProjectionService::should_register( true ) );
		$this->assertFalse( MultiCurrencyPreOrdersCompatibilityProjectionService::should_register( false ) );
	}

	/**
	 * @testdox Should only convert fee args with an amount.
	 */
	public function test_only_converts_fee_args_with_amount(): void {
		$this->assertTrue(
			MultiCurrencyPreOrdersCompatibilityProjectionService::should_convert_fee_amount(
				array( 'amount' => '12.00' )
			)
		);
		$this->assertFalse(
			MultiCurrencyPreOrdersCompatibilityProjectionService::should_convert_fee_amount(
				array( 'name' => 'Pre-order fee' )
			)
		);
	}
}
```

- [ ] **Step 2: Add registry expectations**

In
`MultiCurrencyRuntimeRegistryTest::test_exposes_core_hook_groups_when_core_owns_runtime()`,
insert `pre_orders_compatibility` after `bookings_compatibility` and before
`subscriptions_compatibility`.

Add:

```php
/**
 * @testdox Should preserve the WooPayments Pre-Orders compatibility hook surface.
 */
public function test_pre_orders_compatibility_manifest_contains_preserved_hooks(): void {
	$hook_groups  = MultiCurrencyRuntimeRegistry::get_core_hook_groups();
	$filter_hooks = array_column( $hook_groups['pre_orders_compatibility']['filters'], 'hook' );

	$this->assertSame( array( 'wc_pre_orders_fee' ), $filter_hooks );
	$this->assertSame( array(), $hook_groups['pre_orders_compatibility']['actions'] );
}
```

- [ ] **Step 3: Run RED projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPreOrdersCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyPreOrdersCompatibilityProjectionService`
and the `pre_orders_compatibility` registry group do not exist yet.

## Task 2: GREEN Projection And Registry

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyPreOrdersCompatibilityProjectionService.php`
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Add the projection service**

Create the projection service:

```php
<?php
/**
 * MultiCurrencyPreOrdersCompatibilityProjectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Projects multi-currency Pre-Orders compatibility decisions without hooks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyPreOrdersCompatibilityProjectionService {

	public static function get_hook_manifest(): array {
		return array(
			'filters' => array(
				array(
					'hook'          => 'wc_pre_orders_fee',
					'callback'      => 'convert_pre_orders_fee',
					'priority'      => 10,
					'accepted_args' => 1,
				),
			),
			'actions' => array(),
		);
	}

	public static function should_register( bool $pre_orders_available ): bool {
		return $pre_orders_available;
	}

	public static function should_convert_fee_amount( array $args ): bool {
		return array_key_exists( 'amount', $args );
	}
}
```

- [ ] **Step 2: Add the registry group**

In `MultiCurrencyRuntimeRegistry`, import
`MultiCurrencyPreOrdersCompatibilityProjectionService` and add:

```php
'pre_orders_compatibility' => MultiCurrencyPreOrdersCompatibilityProjectionService::get_hook_manifest(),
```

Place it after `bookings_compatibility` and before
`subscriptions_compatibility`.

- [ ] **Step 3: Run GREEN projection tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPreOrdersCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 3: RED Controller Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityControllerTest.php`

- [ ] **Step 1: Add controller tests**

Create tests that assert:

- Plugin-owned runtime registers no Pre-Orders hooks.
- Core runtime without Pre-Orders available registers no hooks.
- Core runtime before `plugins_loaded` defers registration to
  `plugins_loaded` priority 20.
- Core runtime with Pre-Orders available registers `wc_pre_orders_fee` once.
- `convert_pre_orders_fee()` converts `amount` through product price
  projection and preserves other args.
- `convert_pre_orders_fee()` passes through arrays without `amount`.
- WooCommerce bootstrap resolves and registers
  `MultiCurrencyPreOrdersCompatibilityController`.

Use a test subclass to override:

```php
protected function is_pre_orders_runtime_available(): bool
protected function have_plugins_loaded(): bool
```

Inject a deterministic `MultiCurrencyPriceProjectionService` that returns
`9.5` for product prices.

- [ ] **Step 2: Run RED controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPreOrdersCompatibilityControllerTest|MultiCurrencyPreOrdersCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: fail because `MultiCurrencyPreOrdersCompatibilityController` and its
bootstrap registration do not exist yet.

## Task 4: GREEN Controller And Bootstrap

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php`
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller**

Create a controller following the Bookings/Subcriptions compatibility pattern:

```php
class MultiCurrencyPreOrdersCompatibilityController implements RegisterHooksInterface {
	private MultiCurrencyRuntimeArbiter $arbiter;
	private ?MultiCurrencyPriceProjectionService $price_projection_service = null;

	final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	public function set_price_projection_service( MultiCurrencyPriceProjectionService $price_projection_service ): void {
		$this->price_projection_service = $price_projection_service;
	}

	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		if ( $this->is_pre_orders_runtime_available() || $this->have_plugins_loaded() ) {
			$this->register_pre_orders_filters();
			return;
		}

		$this->add_action_once( 'plugins_loaded', array( $this, 'register_pre_orders_filters' ), 20 );
	}

	public function register_pre_orders_filters(): void {
		if (
			! $this->arbiter->should_core_register()
			|| ! MultiCurrencyPreOrdersCompatibilityProjectionService::should_register(
				$this->is_pre_orders_runtime_available()
			)
		) {
			return;
		}

		foreach ( MultiCurrencyPreOrdersCompatibilityProjectionService::get_hook_manifest()['filters'] as $filter ) {
			$this->add_filter_once(
				(string) $filter['hook'],
				array( $this, (string) $filter['callback'] ),
				(int) $filter['priority'],
				(int) $filter['accepted_args']
			);
		}
	}

	public function convert_pre_orders_fee( array $args ): array {
		if ( ! MultiCurrencyPreOrdersCompatibilityProjectionService::should_convert_fee_amount( $args ) ) {
			return $args;
		}

		$args['amount'] = $this->get_price_projection_service()->get_price( $args['amount'], 'product' );
		return $args;
	}
}
```

The committed class must include the lazy price projection service and
`add_filter_once()`/`add_action_once()` helpers, matching the surrounding
multi-currency controllers.

- [ ] **Step 2: Register the controller in WooCommerce bootstrap**

In `plugins/woocommerce/includes/class-woocommerce.php`, add:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyPreOrdersCompatibilityController::class )->register();
```

Place it near the other multi-currency compatibility controllers.

- [ ] **Step 3: Run GREEN controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPreOrdersCompatibilityControllerTest|MultiCurrencyPreOrdersCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: pass.

## Task 5: Verification, Changelog, And Commits

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2am-pre-orders-compatibility`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix

Preserve native multi-currency conversion for WooCommerce Pre-Orders fees.
```

- [ ] **Step 2: Run final gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyPreOrdersCompatibilityControllerTest|MultiCurrencyPreOrdersCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyBookingsCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityControllerTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyPreOrdersCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyPreOrdersCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php tests/php/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyPreOrdersCompatibilityProjectionServiceTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint --fix .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2am.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2am.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

Expected: all commands pass. If PHPCS auto-fixes are needed, rerun the focused
PHPUnit and PHPCS commands after applying them.

- [ ] **Step 3: Commit source/tests**

Stage only source/test/bootstrap/registry files, not changelog or plan docs.
Run staged gates:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
git diff --cached --check
```

Commit:

```bash
git commit -m "fix(payments): add pre-orders multi-currency compatibility"
```

- [ ] **Step 4: Commit changelog**

Stage only the changelog entry and commit:

```bash
git commit -m "chore(payments): add pre-orders compatibility changelog"
```

- [ ] **Step 5: Log git range**

Use the previous commit before this slice as the range start:

```text
a9cb1c0aac3da258aa9b5d62f8b1a4ca10166e0f...<b2am-changelog-commit>
```

Record the range and gate evidence in both logs. Keep WPCOM read-only and do
not commit or push anything in WPCOM.
