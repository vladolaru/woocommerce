# Core Native Payments B3h Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Inject `MultiCurrencyProjectionServiceFactory` through the remaining multi-currency projection consumers instead of letting controllers pull it from the container inside lazy service methods.

**Architecture:** B3e centralized duplicated projection service graph construction in `MultiCurrencyProjectionServiceFactory`, but the consuming controllers still locate that factory via `wc_get_container()` from private getters. This pass keeps the factory as the construction boundary, makes each controller's dependencies explicit through `init()`, preserves existing `set_*_projection_service()` test seams, and adds source-boundary coverage so direct controller container lookups do not return.

**Tech Stack:** WooCommerce Core internal PHP services, PSR-4 classes under `plugins/woocommerce/src/Internal/MultiCurrency`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Boundary Test

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php`

- [ ] **Step 1: Add failing source-boundary coverage**

Add a test that scans the remaining projection consumers and fails if any controller still calls `wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class )`.

```php
/**
 * @testdox Should inject projection service factory access into controllers.
 */
public function test_projection_service_factory_access_is_injected_into_controllers(): void {
	$source_files = array(
		'src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php',
		'src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php',
		'src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php',
		'src/Internal/MultiCurrency/MultiCurrencyRestController.php',
		'src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php',
		'src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php',
		'src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php',
		'src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php',
		'src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php',
		'src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php',
		'src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php',
	);

	foreach ( $source_files as $source_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source for domain-boundary regression coverage.
		$source = (string) file_get_contents( WC()->plugin_path() . '/' . $source_file );

		$this->assertDoesNotMatchRegularExpression(
			'/wc_get_container\(\)\s*->get\(\s*MultiCurrencyProjectionServiceFactory::class\s*\)/',
			$source,
			"{$source_file} should receive the projection service factory through init injection."
		);
	}
}
```

- [ ] **Step 2: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest'`

Expected: FAIL because `MultiCurrencyFrontendPricesController.php` and the other listed consumers still use direct container lookups for `MultiCurrencyProjectionServiceFactory`.

### Task 2: Controller DI Migration

**Files:**
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php`

- [ ] **Step 1: Add explicit factory dependency**

In each controller, add a private `MultiCurrencyProjectionServiceFactory $projection_service_factory` property, add the factory parameter to `init()`, and assign it. Preserve the existing `MultiCurrencyStateBuilderFactory` parameters where present.

Example for a controller that currently only receives the arbiter:

```php
/**
 * Projection service factory.
 *
 * @var MultiCurrencyProjectionServiceFactory
 */
private MultiCurrencyProjectionServiceFactory $projection_service_factory;

/**
 * Initialize the class instance.
 *
 * @internal
 *
 * @param MultiCurrencyRuntimeArbiter           $arbiter                    Runtime owner arbiter.
 * @param MultiCurrencyProjectionServiceFactory $projection_service_factory Projection service factory.
 */
final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyProjectionServiceFactory $projection_service_factory ): void {
	$this->arbiter                    = $arbiter;
	$this->projection_service_factory = $projection_service_factory;
}
```

Example for a controller that already receives a state-builder factory:

```php
/**
 * @param MultiCurrencyRuntimeArbiter           $arbiter                    Runtime owner arbiter.
 * @param MultiCurrencyStateBuilderFactory      $state_builder_factory      State builder factory.
 * @param MultiCurrencyProjectionServiceFactory $projection_service_factory Projection service factory.
 */
final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyStateBuilderFactory $state_builder_factory, MultiCurrencyProjectionServiceFactory $projection_service_factory ): void {
	$this->arbiter                    = $arbiter;
	$this->state_builder_factory      = $state_builder_factory;
	$this->projection_service_factory = $projection_service_factory;
}
```

- [ ] **Step 2: Replace lazy container lookups**

Replace each direct lookup with the injected factory while preserving which projection service gets created.

```php
$this->price_projection_service = $this->projection_service_factory->create_price_projection_service();
$this->frontend_projection_service = $this->projection_service_factory->create_frontend_projection_service();
```

- [ ] **Step 3: Preserve explicit test seams**

Do not remove existing setters such as `set_price_projection_service()`, `set_frontend_projection_service()`, or controller-specific projection setters. Those seams keep focused tests cheap and avoid constructing the full graph in tests that inject doubles.

### Task 3: Verification and Commit

**Files:**
- Modify: `plugins/woocommerce/changelog/add-native-payments-b3h-projection-factory-di`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest|MultiCurrencyAsyncPriceRendererControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyPreOrdersCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencyBookingsCompatibilityControllerTest|MultiCurrencyNameYourPriceCompatibilityControllerTest|MultiCurrencyRestControllerTest|MultiCurrencyProductAddOnsCompatibilityControllerTest|MultiCurrencyDepositsCompatibilityControllerTest|MultiCurrencyShadowModeTest|MultiCurrencyProjectionServiceFactoryTest'`

Expected: PASS.

- [ ] **Step 2: Run static gates**

Run PHPStan for the touched production controllers and `MultiCurrencyDomainMapTest.php`. Run scoped PHPCS for the touched PHP files. Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, then staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged` and `git diff --cached --check` before committing. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 3: Commit source/tests and changelog separately**

Commit source/tests first with `refactor(payments): inject multi-currency projection factory`, then commit the changelog with `chore(payments): add projection factory DI changelog`.

- [ ] **Step 4: Record evidence**

Append B3h final evidence above the implementation-log append marker and above the staging-log append marker. Include the focused test result, PHPStan/PHPCS/lint evidence, commit hashes, and git range.
