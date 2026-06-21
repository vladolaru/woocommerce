---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 11:58
status: draft
---

# Core Native Payments B3k Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize the remaining multi-currency controller-owned runtime service graph construction behind one native multi-currency service factory.

**Architecture:** B3g and B3h injected the state-builder and projection factories, but several controllers still directly instantiate request context, order context, selected-currency persistence, geolocation, switcher/tracking/analytics projections, and store-currency lifecycle services. B3k adds `MultiCurrencyRuntimeServiceFactory` as the next composition layer above `MultiCurrencyStateBuilderFactory`, then rewires those controllers to lazily obtain default collaborators from the factory while preserving existing explicit setter seams for tests and future bootstrap definitions. Pure service tests and the deliberate `NativeWooPaymentsGateway` direct-instantiation fallback remain out of scope.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/MultiCurrency`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

### Task 1: Boundary and Factory Tests

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRuntimeServiceFactoryTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php`

- [ ] **Step 1: Add factory coverage**

Create tests that expect `MultiCurrencyRuntimeServiceFactory` to create request context, order context, selected-currency persistence, geolocation, switcher projection, analytics projection, analytics SQL projection, tracking projection, tracking order-count projection, and store-currency lifecycle services. Include a store-currency lifecycle assertion that the returned service is a `MultiCurrencyStoreCurrencyLifecycleService`, because the shared-cache invariant is covered by that service’s existing behavior tests.

- [ ] **Step 2: Add source-boundary coverage**

Extend `MultiCurrencyDomainMapTest` with a source-boundary test over `MultiCurrencyFrontendPricesController.php`, `MultiCurrencySelectedCurrencyController.php`, `MultiCurrencyFrontendCurrenciesController.php`, `MultiCurrencyAsyncPriceRendererController.php`, `MultiCurrencyRestRequestOverrideController.php`, `MultiCurrencyAnalyticsController.php`, `MultiCurrencyTrackingController.php`, `MultiCurrencySwitcherBlockController.php`, `MultiCurrencySwitcherWidgetController.php`, `MultiCurrencyStorefrontIntegrationController.php`, and `MultiCurrencyStoreCurrencyLifecycleController.php`. The test should assert those controllers no longer contain direct construction of `MultiCurrencyRequestContext`, `MultiCurrencyOrderContextService`, `MultiCurrencySelectedCurrencyPersistenceService`, `MultiCurrencyGeolocationService`, `MultiCurrencySwitcherProjectionService`, `MultiCurrencyAnalyticsProjectionService`, `MultiCurrencyAnalyticsSqlProjectionService`, `MultiCurrencyTrackingProjectionService`, `MultiCurrencyTrackingOrderCountProjectionService`, `MultiCurrencyStoreCurrencyLifecycleService`, or `MultiCurrencyDatabaseCache`.

- [ ] **Step 3: Run RED**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeServiceFactoryTest|MultiCurrencyDomainMapTest'`

Expected: FAIL because `MultiCurrencyRuntimeServiceFactory` does not exist and the listed controllers still directly instantiate several of the target services.

### Task 2: Runtime Service Factory

**Files:**
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRuntimeServiceFactory.php`

- [ ] **Step 1: Add the factory**

Create `MultiCurrencyRuntimeServiceFactory` with an injected `MultiCurrencyStateBuilderFactory` and factory methods for the default runtime services: `create_request_context()`, `create_order_context_service()`, `create_geolocation_service()`, `create_selected_currency_persistence_service()`, `create_switcher_projection_service()`, `create_analytics_projection_service()`, `create_analytics_sql_projection_service()`, `create_tracking_projection_service()`, `create_tracking_order_count_projection_service()`, and `create_store_currency_lifecycle_service()`.

- [ ] **Step 2: Preserve cache and state-builder boundaries**

`create_store_currency_lifecycle_service()` must create one `MultiCurrencyDatabaseCache` instance and pass that same cache both to `MultiCurrencyStoreCurrencyLifecycleService` and to `MultiCurrencyStateBuilderFactory::create( null, $cache )`. The projection factory methods should accept an optional `MultiCurrencyStateBuilder` when the controller already owns one.

### Task 3: Controller Rewire

**Files:**
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendPricesController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyFrontendCurrenciesController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestRequestOverrideController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyTrackingController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php`

- [ ] **Step 1: Inject the factory**

Add `MultiCurrencyRuntimeServiceFactory` to each target controller’s `init()` signature and store it in a private property. Remove now-unused `MultiCurrencyStateBuilderFactory` properties from controllers where it was only used to construct one of the moved services.

- [ ] **Step 2: Replace direct construction**

Replace each private lazy getter’s direct `new` expression with the corresponding factory method. Keep each existing `set_*` test seam intact. For `MultiCurrencyStorefrontIntegrationController`, continue to cache and reuse its state builder for activation checks, and pass that state builder into `create_switcher_projection_service( $this->get_state_builder() )`.

- [ ] **Step 3: Keep runtime behavior unchanged**

Do not change hook names, priorities, request predicates, option keys, selected-currency behavior, Storefront simulation behavior, analytics SQL behavior, tracking query behavior, or store-currency synchronization timing.

### Task 4: Test Updates and Verification

**Files:**
- Modify matching controller tests under `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/`
- Create: `plugins/woocommerce/changelog/add-native-payments-b3k-mc-runtime-service-factory`
- Modify session docs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [ ] **Step 1: Update manual controller initializers**

Update test helpers that instantiate the target controllers manually to pass `wc_get_container()->get( MultiCurrencyRuntimeServiceFactory::class )`. Keep existing explicit setter seams so tests remain focused on controller behavior instead of factory internals.

- [ ] **Step 2: Run focused GREEN tests**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeServiceFactoryTest|MultiCurrencyDomainMapTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyAsyncPriceRendererControllerTest|MultiCurrencyRestRequestOverrideControllerTest|MultiCurrencyAnalyticsControllerTest|MultiCurrencyTrackingControllerTest|MultiCurrencySwitcherBlockControllerTest|MultiCurrencySwitcherWidgetControllerTest|MultiCurrencyStorefrontIntegrationControllerTest|MultiCurrencyStoreCurrencyLifecycleControllerTest'`

Expected: PASS.

- [ ] **Step 3: Run static gates**

Run PHPStan for the new factory and touched production controllers, scoped PHPCS for touched source/tests, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, staged `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, and `git diff --cached --check`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 4: Commit and record evidence**

Commit source/tests and changelog according to project conventions, then append B3k evidence above the implementation-log append marker and staging-log append marker. Include the RED failure, focused GREEN result, static gates, commit hashes, and git range.
