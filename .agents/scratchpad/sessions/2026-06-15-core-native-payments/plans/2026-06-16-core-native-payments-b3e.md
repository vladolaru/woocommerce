---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 10:43
status: draft
---

# Core Native Payments B3e Projection Service Factory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize native multi-currency projection service graph construction and migrate all duplicated price/frontend projection consumers in one pass.

**Architecture:** Add `MultiCurrencyProjectionServiceFactory` under the multi-currency services namespace. The factory owns the shared graph construction for `MultiCurrencyPriceProjectionService` and `MultiCurrencyFrontendProjectionService`: localization, state builder, price calculator, and geolocation. Controllers keep their existing test seams for explicit projection-service injection, but their production lazy defaults delegate to the factory instead of rebuilding the graph locally.

**Tech Stack:** WooCommerce Core PHP, DI container lazy access through concrete internal classes, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, plugin changelog file.

---

## Files

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactory.php`.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactoryTest.php`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php` to pin the architecture rule that controllers/shadow mode do not directly instantiate price/frontend projection services or price calculators.
- Modify price projection consumers:
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php`
- Modify frontend projection consumers:
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php`
- Create: `plugins/woocommerce/changelog/add-native-payments-b3e-projection-service-factory`.

### Task 1: Add RED Factory and Boundary Coverage

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactoryTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php`

- [ ] **Step 1: Add factory tests**

Create `MultiCurrencyProjectionServiceFactoryTest` with:

```php
/**
 * @testdox Should create price projection services from one shared localization graph.
 */
public function test_creates_price_projection_service(): void {
	$sut = wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class );

	$service = $sut->create_price_projection_service();

	$this->assertInstanceOf( MultiCurrencyPriceProjectionService::class, $service );
}

/**
 * @testdox Should create frontend projection services from one shared localization graph.
 */
public function test_creates_frontend_projection_service(): void {
	$sut = wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class );

	$service = $sut->create_frontend_projection_service();

	$this->assertInstanceOf( MultiCurrencyFrontendProjectionService::class, $service );
}
```

- [ ] **Step 2: Add a source-boundary test for projection construction**

In `MultiCurrencyDomainMapTest`, add a test that reads these exact source files and asserts they do not contain direct `new MultiCurrencyPriceProjectionService(`, `new MultiCurrencyFrontendProjectionService(`, or `new MultiCurrencyPriceCalculator(` construction:

```php
$files = array(
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
```

- [ ] **Step 3: Run RED PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProjectionServiceFactoryTest|MultiCurrencyDomainMapTest'`

Expected: FAIL because `MultiCurrencyProjectionServiceFactory` does not exist yet and the source-boundary test still finds direct projection-service construction in controllers.

### Task 2: Implement the Factory and Migrate Consumers

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactory.php`
- Modify all production consumers listed in the Files section.

- [ ] **Step 1: Implement the factory**

```php
class MultiCurrencyProjectionServiceFactory {
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

	final public function init( MultiCurrencyStateBuilderFactory $state_builder_factory ): void {
		$this->state_builder_factory = $state_builder_factory;
	}

	public function create_price_projection_service(
		?MultiCurrencyLocalizationInterface $localization_service = null,
		?MultiCurrencyStateBuilder $state_builder = null
	): MultiCurrencyPriceProjectionService {
		$localization_service = $localization_service ?? new MultiCurrencyLocalizationService();
		$state_builder        = $state_builder ?? $this->state_builder_factory->create( $localization_service );

		return new MultiCurrencyPriceProjectionService(
			$state_builder,
			new MultiCurrencyPriceCalculator( $localization_service )
		);
	}

	public function create_frontend_projection_service(
		?MultiCurrencyLocalizationInterface $localization_service = null,
		?MultiCurrencyStateBuilder $state_builder = null
	): MultiCurrencyFrontendProjectionService {
		$localization_service = $localization_service ?? new MultiCurrencyLocalizationService();
		$state_builder        = $state_builder ?? $this->state_builder_factory->create( $localization_service );

		return new MultiCurrencyFrontendProjectionService(
			$state_builder,
			$localization_service,
			new MultiCurrencyGeolocationService( $localization_service )
		);
	}
}
```

- [ ] **Step 2: Replace price projection default construction in all price consumers**

Each price consumer should keep its existing explicit `set_price_projection_service()` or `set_projection_service()` test seam. In its private lazy getter, replace manual localization/state/calculator construction with one of:

```php
$this->price_projection_service = wc_get_container()
	->get( MultiCurrencyProjectionServiceFactory::class )
	->create_price_projection_service();
```

or, for consumers that must reuse a state builder:

```php
$this->price_projection_service = wc_get_container()
	->get( MultiCurrencyProjectionServiceFactory::class )
	->create_price_projection_service( null, $this->get_state_builder() );
```

For shadow mode, preserve the read-only localization boundary:

```php
$this->projection_service = wc_get_container()
	->get( MultiCurrencyProjectionServiceFactory::class )
	->create_price_projection_service( $this->create_read_only_localization_service() );
```

- [ ] **Step 3: Replace frontend projection default construction in all frontend consumers**

Each frontend consumer should keep its existing explicit `set_frontend_projection_service()` test seam. In its private lazy getter, replace manual localization/state/geolocation construction with:

```php
$this->frontend_projection_service = wc_get_container()
	->get( MultiCurrencyProjectionServiceFactory::class )
	->create_frontend_projection_service();
```

For REST, preserve the existing state-builder seam:

```php
$this->frontend_projection_service = wc_get_container()
	->get( MultiCurrencyProjectionServiceFactory::class )
	->create_frontend_projection_service( null, $this->get_state_builder() );
```

- [ ] **Step 4: Run focused GREEN PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProjectionServiceFactoryTest|MultiCurrencyDomainMapTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyAsyncPriceRendererControllerTest|MultiCurrencyRestControllerTest|MultiCurrencyBookingsCompatibilityControllerTest|MultiCurrencyDepositsCompatibilityControllerTest|MultiCurrencyProductAddOnsCompatibilityControllerTest|MultiCurrencyNameYourPriceCompatibilityControllerTest|MultiCurrencyPreOrdersCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencyShadowModeTest'`

Expected: PASS, preserving all migrated consumers while proving construction has moved into the factory.

### Task 3: Verification, Changelog, and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b3e-projection-service-factory`

- [ ] **Step 1: Add the changelog entry**

```text
Significance: patch
Type: dev
Comment: Centralize native multi-currency projection service construction behind a factory.
```

- [ ] **Step 2: Run PHPStan for touched production files**

Run PHPStan for the factory plus all touched controllers:

```bash
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactory.php src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php src/Internal/MultiCurrency/MultiCurrencyRestController.php src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php --memory-limit=2G
```

Expected: No errors.

- [ ] **Step 3: Run scoped PHPCS for touched PHP files**

Run PHPCS for the touched source and test files:

```bash
cd plugins/woocommerce && vendor/bin/phpcs -s src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactory.php src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php src/Internal/MultiCurrency/MultiCurrencyRestController.php src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactoryTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php
```

Expected: No errors for touched files.

- [ ] **Step 4: Run changed-file lint and whitespace checks**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes && git diff --check`

Expected: PASS. Do not run markdownlint against `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 5: Stage source/test files and run staged checks**

Run: `git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactory.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyBookingsCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyDepositsCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPreOrdersCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php plugins/woocommerce/src/Internal/MultiCurrency/Shadow/MultiCurrencyShadowMode.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyProjectionServiceFactoryTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php && pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged && git diff --cached --check`

Expected: Both staged checks pass.

- [ ] **Step 6: Commit source/tests**

Run: `git commit -m "refactor(payments): centralize multi-currency projection services"`

Expected: Commit succeeds. If signing/authentication fails, leave files staged and record the pending commit instead of retrying with altered signing flags.

- [ ] **Step 7: Stage and commit changelog**

Run: `git add plugins/woocommerce/changelog/add-native-payments-b3e-projection-service-factory && git commit -m "chore(payments): add projection factory cleanup changelog"`

Expected: Commit succeeds. If signing/authentication fails, leave the changelog staged and record the pending commit instead of retrying with altered signing flags.

## Self-Review

- Spec coverage: The plan covers the full repeated price/frontend projection construction graph rather than one controller at a time.
- Placeholder scan: No `TBD`, `TODO`, `implement later`, or unnamed follow-up placeholders are present.
- Type consistency: Factory methods use concrete internal services and optional localization/state-builder seams so existing controller test seams can remain intact.
