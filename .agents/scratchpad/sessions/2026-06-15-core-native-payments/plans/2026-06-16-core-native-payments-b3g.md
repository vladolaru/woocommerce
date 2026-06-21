---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 11:00
status: draft
---

# Core Native Payments B3g State Builder Factory DI Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the remaining direct `MultiCurrencyStateBuilderFactory` container lookups from multi-currency controllers in one pass.

**Architecture:** Keep `MultiCurrencyStateBuilderFactory` as the existing construction seam, but make controller dependencies explicit through `init()` injection instead of hidden `wc_get_container()->get()` calls inside lazy getters. Controllers that need custom localization or cache boundaries still pass those boundaries to the injected factory. Existing explicit `set_state_builder()` and projection-service test seams stay intact.

**Tech Stack:** WooCommerce Core PHP, concrete DI `init()` injection, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, plugin changelog file.

## Files

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php` to add a boundary test that prevents direct `wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )` usage in migrated controllers.
- Modify controllers:
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyTrackingController.php`
  - `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php`
- Modify tests with manual controller `init()` calls for those controllers.
- Create: `plugins/woocommerce/changelog/add-native-payments-b3g-state-builder-factory-di`.

## Task 1: Add RED Boundary Coverage

- [ ] Add `MultiCurrencyDomainMapTest::test_state_builder_factory_access_is_injected_into_controllers()` with a source-file list covering the 12 migrated controllers.
- [ ] In the test, read each local source file and assert it does not match `wc_get_container\(\)\s*->get\(\s*MultiCurrencyStateBuilderFactory::class\s*\)`.
- [ ] RED command: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest'`.
- [ ] Expected RED: the new boundary test fails because the listed controllers still contain direct state-builder factory container lookups.

## Task 2: Inject State Builder Factory Through Controllers

- [ ] Add a `private MultiCurrencyStateBuilderFactory $state_builder_factory;` property to each controller in scope.
- [ ] Append `MultiCurrencyStateBuilderFactory $state_builder_factory` to each controller `init()` signature and store it on the new property. Preserve existing argument order for current required dependencies, adding the new dependency at the end.
- [ ] Replace direct `wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->create(...)` calls with `$this->state_builder_factory->create(...)`.
- [ ] Preserve custom boundaries: Points Rewards and Name Your Price still pass `new MultiCurrencyLocalizationService()`, and Store Currency Lifecycle still passes the local `MultiCurrencyDatabaseCache`.
- [ ] Update manual test fixture `init()` calls to pass `wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )`.
- [ ] GREEN command: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest|MultiCurrencyAnalyticsControllerTest|MultiCurrencyPointsRewardsCompatibilityControllerTest|MultiCurrencySubscriptionsCompatibilityControllerTest|MultiCurrencyNameYourPriceCompatibilityControllerTest|MultiCurrencySwitcherBlockControllerTest|MultiCurrencyStorefrontIntegrationControllerTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyStoreCurrencyLifecycleControllerTest|MultiCurrencyRestControllerTest|MultiCurrencyCompatibilityControllerTest|MultiCurrencyTrackingControllerTest|MultiCurrencySwitcherWidgetControllerTest'`.

## Task 3: Changelog and Gates

- [ ] Add changelog entry `plugins/woocommerce/changelog/add-native-payments-b3g-state-builder-factory-di` with `Significance: patch`, `Type: dev`, and a comment about injecting native multi-currency state-builder factory dependencies into controllers.
- [ ] PHPStan: `composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php src/Internal/MultiCurrency/MultiCurrencyRestController.php src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyTrackingController.php src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php --memory-limit=2G`.
- [ ] PHPCS: `vendor/bin/phpcs -s src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationController.php src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleController.php src/Internal/MultiCurrency/MultiCurrencyRestController.php src/Internal/MultiCurrency/MultiCurrencyCompatibilityController.php src/Internal/MultiCurrency/MultiCurrencyTrackingController.php src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyAnalyticsControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyPointsRewardsCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyStorefrontIntegrationControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyStoreCurrencyLifecycleControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRestControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyTrackingControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetControllerTest.php`.
- [ ] Changed-file gates: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, and `git diff --cached --check`.
- [ ] Commit source/tests as `refactor(payments): inject multi-currency state builder factory`.
- [ ] Commit changelog as `chore(payments): add state builder factory cleanup changelog`.

## Acceptance Notes

- No new factory or abstraction is introduced; this chunk makes the existing factory dependency explicit.
- No WPCOM code changes, commits, or pushes.
- No WooPayments plugin checkout changes.
- Scratchpad docs are not linted or committed.
