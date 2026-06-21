# B2ar Name Your Price Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the native multi-currency compatibility adapter for WooCommerce Name Your Price.

**Architecture:** Add a projection service for the WooPayments hook manifest and pure decisions, then add a runtime-gated controller for hook registration, selected-currency price projection, raw cart/edit conversions, product meta updates, and bootstrap wiring. Keep WPCOM read-only and make no WooPayments client edits.

**Tech Stack:** WooCommerce Core PHP, WordPress hooks, PHPUnit through `wp-env`, PHPStan, PHPCS, pnpm scripts.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyNameYourPriceCompatibilityProjectionService.php`.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php`.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyNameYourPriceCompatibilityProjectionServiceTest.php`.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityControllerTest.php`.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`.
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`.
- Add `plugins/woocommerce/changelog/add-native-payments-b2ar-name-your-price-compatibility`.

## Task 1: Projection Service RED/GREEN

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyNameYourPriceCompatibilityProjectionServiceTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyNameYourPriceCompatibilityProjectionService.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Write failing tests** covering the preserved hooks `wc_nyp_raw_minimum_price`, `wc_nyp_raw_maximum_price`, `wc_nyp_raw_suggested_price`, `woocommerce_get_cart_item_from_session`, `wcpay_multi_currency_should_convert_product_price`, `wc_nyp_edit_in_cart_args`, `wc_nyp_get_initial_price`, and action `woocommerce_add_cart_item_data`; registration requires `WC_Name_Your_Price`; pure decisions cover raw price conversion, initial currency storage, cart-session conversion, product-price conversion suppression, and edit initial-price conversion.
- [ ] **Step 2: Run RED** with `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyNameYourPriceCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'`; expect the service and registry group to be missing.
- [ ] **Step 3: Implement projection and registry group** with `name_your_price_compatibility => MultiCurrencyNameYourPriceCompatibilityProjectionService::get_hook_manifest()`.
- [ ] **Step 4: Run GREEN** with the same command.

## Task 2: Controller RED/GREEN

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityControllerTest.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyNameYourPriceCompatibilityController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Write failing tests** covering guarded registration, `plugins_loaded` deferral, NYP raw price conversion via `get_price( ..., 'product' )`, initial cart currency/original amount persistence, cart-session conversion/restoration, product-price conversion suppression, edit-in-cart currency args, initial edit-price raw conversion from request values, and bootstrap registration.
- [ ] **Step 2: Run RED** with `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyNameYourPriceCompatibilityControllerTest|MultiCurrencyNameYourPriceCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'`; expect the controller and bootstrap registration to be missing.
- [ ] **Step 3: Implement controller** with `set_price_projection_service()`, `set_state_builder()`, protected runtime/helper seams, request-value parsing with `wc_clean( wp_unslash( ... ) )`, and lazy `MultiCurrencyStateBuilderFactory`/`MultiCurrencyPriceProjectionService` construction.
- [ ] **Step 4: Run GREEN** with the same focused command.

## Task 3: Verification and Commits

**Files:**

- Add: `plugins/woocommerce/changelog/add-native-payments-b2ar-name-your-price-compatibility`

- [ ] **Step 1: Add changelog** with `Significance: patch`, `Type: fix`, and `Preserve native multi-currency conversion for WooCommerce Name Your Price compatibility.`
- [ ] **Step 2: Run final gates**: focused regression PHPUnit including Deposits and Points and Rewards neighbors, PHPStan for the new controller/projection/registry, scoped PHPCS for touched PHP files, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `git diff --check`.
- [ ] **Step 3: Commit source and changelog separately** using `fix(payments): add name your price multi-currency compatibility` and `chore(payments): add name your price compatibility changelog`.

## Self-Review

- Spec coverage: Hook manifest, runtime gating, raw price conversion, cart currency persistence, cart-session conversion, product conversion suppression, edit args, request edit-price conversion, bootstrap, registry, tests, changelog, and verification are covered.
- Placeholder scan: No placeholder-only tasks remain.
- Type consistency: Planned class and method names match the existing multi-currency compatibility adapter pattern.
