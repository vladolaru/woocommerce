# B2as Product Add-ons Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments multi-currency compatibility behavior for WooCommerce Product Add-ons when WooCommerce Core owns the native multi-currency runtime.

**Architecture:** Add a Product Add-ons projection service that declares the preserved hook surface and pure conversion decisions, then add a Core-owned controller that registers those hooks only under the runtime arbiter and Product Add-ons availability guards. The controller should mirror WooPayments behavior while using native `MultiCurrencyPriceProjectionService` for selected-currency product conversions and small protected seams for Product Add-ons helper formatting in tests.

**Tech Stack:** WooCommerce Core PHP, WordPress hooks, PHPUnit through `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyProductAddOnsCompatibilityProjectionService.php` for the hook manifest and pure Product Add-ons compatibility decisions.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php` for runtime-guarded hook registration and Product Add-ons callbacks.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyProductAddOnsCompatibilityProjectionServiceTest.php` for manifest and decision tests.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityControllerTest.php` for hook registration, callback behavior, bootstrap, and conversion edge cases.
- Modify `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php` to add `product_addons_compatibility`.
- Modify `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php` to assert the Product Add-ons hook group.
- Modify `plugins/woocommerce/includes/class-woocommerce.php` to register `MultiCurrencyProductAddOnsCompatibilityController`.
- Add `plugins/woocommerce/changelog/add-native-payments-b2as-product-add-ons-compatibility`.

## Task 1: Projection Service and Registry Manifest

- [ ] **Step 1: Write failing projection and registry tests.**

Create `MultiCurrencyProductAddOnsCompatibilityProjectionServiceTest` with tests for:

```php
$manifest = MultiCurrencyProductAddOnsCompatibilityProjectionService::get_hook_manifest();
$this->assertSame( array( 'woocommerce_product_addons_option_price_raw', 'woocommerce_product_addons_price_raw', 'woocommerce_product_addons_params', 'woocommerce_product_addons_get_item_data', 'woocommerce_product_addons_update_product_price', 'woocommerce_product_addons_order_line_item_meta', 'wcpay_multi_currency_should_convert_product_price', 'woocommerce_product_addons_ajax_get_product_price_including_tax', 'woocommerce_product_addons_ajax_get_product_price_excluding_tax' ), array_column( $manifest['filters'], 'hook' ) );
$this->assertSame( array(), $manifest['actions'] );
$this->assertFalse( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_register( false, false, false, false ) );
$this->assertFalse( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_register( true, true, false, false ) );
$this->assertFalse( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_register( true, false, false, true ) );
$this->assertTrue( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_register( true, false, false, false ) );
$this->assertTrue( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_register( true, true, true, false ) );
$this->assertFalse( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_convert_addon_price( 'percentage_based' ) );
$this->assertTrue( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_convert_addon_price( 'flat_fee' ) );
$this->assertFalse( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_convert_product_price( false, true ) );
$this->assertFalse( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_convert_product_price( true, true ) );
$this->assertTrue( MultiCurrencyProductAddOnsCompatibilityProjectionService::should_convert_product_price( true, false ) );
```

Extend `MultiCurrencyRuntimeRegistryTest` to include `product_addons_compatibility` and assert the Product Add-ons hook list.

- [ ] **Step 2: Run RED.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProductAddOnsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'`.

Expected: failure because `MultiCurrencyProductAddOnsCompatibilityProjectionService` and `product_addons_compatibility` do not exist yet.

- [ ] **Step 3: Implement minimal projection service and registry group.**

Add `MultiCurrencyProductAddOnsCompatibilityProjectionService::get_hook_manifest()` with the seven frontend filters and two AJAX filters at priority 50 using accepted args from WooPayments: raw price filters `2`, params `1`, cart item data `3`, product price update `4`, order line item meta `4`, product conversion guard `2`, AJAX price filters `3`. Add pure helpers for `should_register()`, `should_convert_addon_price()`, `should_convert_product_price()`, and `get_conversion_amount_for_addon()`.

- [ ] **Step 4: Run GREEN.**

Run the same focused PHPUnit command and confirm it passes.

## Task 2: Controller Registration and Callback Behavior

- [ ] **Step 1: Write failing controller tests.**

Create `MultiCurrencyProductAddOnsCompatibilityControllerTest` with tests for:

- Registers Product Add-ons hooks at priority 50 when Core owns multi-currency and Product Add-ons is available.
- Does not register when plugin owns multi-currency, Product Add-ons is missing, admin non-AJAX, or cron.
- Defers registration to `plugins_loaded` when Product Add-ons is not available before plugins load.
- Converts raw add-on prices unless `price_type` is `percentage_based`.
- Updates Product Add-ons params from `wc_get_price_decimals()`, `wc_get_price_decimal_separator()`, and `wc_get_price_thousand_separator()`.
- Suppresses default product-price conversion when `_wcpay_multi_currency_addons_converted` is set to `1`.
- Converts AJAX product calculation price as `(price / quantity)` through the native product price projection and multiplies by quantity, preserving zero-quantity safety.
- Recalculates Product Add-ons updated prices for flat fees, percentage-based add-ons, custom prices, and input multipliers, then marks the product data with `_wcpay_multi_currency_addons_converted`.
- Rewrites cart item data values for flat-fee, quantity-based, custom-price, input-multiplier, and percentage-based display paths.
- Rewrites order line item meta values and `raw_price` for flat-fee, quantity-based, custom-price, input-multiplier, and percentage-based display paths.
- Verifies `class-woocommerce.php` bootstrap registration.

- [ ] **Step 2: Run RED.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProductAddOnsCompatibilityControllerTest|MultiCurrencyProductAddOnsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest'`.

Expected: failure because `MultiCurrencyProductAddOnsCompatibilityController` and bootstrap registration do not exist yet.

- [ ] **Step 3: Implement minimal controller.**

Implement `MultiCurrencyProductAddOnsCompatibilityController` with `init()`, `set_price_projection_service()`, `set_state_builder()`, `register()`, `register_product_addons_hooks()`, `get_addons_price()`, `product_addons_params()`, `get_item_data()`, `update_product_price()`, `order_line_item_meta()`, `get_product_calculation_price()`, and `should_convert_product_price()`. Use protected seams for runtime flags, Product Add-ons helper display, cart/order display filters, product lookup, and price formatting. Register the controller from WooCommerce bootstrap after Name Your Price and before Subscriptions, and add the registry group near the other compatibility groups.

- [ ] **Step 4: Run GREEN.**

Run the focused controller/projection/registry PHPUnit command and confirm it passes.

## Task 3: Final Gates, Logs, and Commits

- [ ] **Step 1: Add changelog.**

Create `plugins/woocommerce/changelog/add-native-payments-b2as-product-add-ons-compatibility`:

```text
Significance: patch
Type: fix

Preserve native multi-currency conversion for WooCommerce Product Add-ons compatibility.
```

- [ ] **Step 2: Run final gates.**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProductAddOnsCompatibilityControllerTest|MultiCurrencyProductAddOnsCompatibilityProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyNameYourPriceCompatibilityControllerTest|MultiCurrencyDepositsCompatibilityControllerTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyProductAddOnsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityController.php src/Internal/MultiCurrency/Services/MultiCurrencyProductAddOnsCompatibilityProjectionService.php src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php tests/php/src/Internal/MultiCurrency/MultiCurrencyProductAddOnsCompatibilityControllerTest.php tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyProductAddOnsCompatibilityProjectionServiceTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

- [ ] **Step 3: Commit source/tests and changelog separately.**

Stage only source/test/bootstrap/registry files for the first commit, run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged` and `git diff --cached --check`, then commit `fix(payments): add product add-ons multi-currency compatibility`. Stage only the changelog file for the second commit and commit `chore(payments): add product add-ons compatibility changelog`.

- [ ] **Step 4: Update logs.**

Insert B2as evidence above `IMPLEMENTATION_LOG_APPEND_POINT` in `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md` and above `NEXT_WORK_PACKAGE_APPEND_POINT` in `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`. Do not lint scratchpad docs.

## Self-Review

- Spec coverage: The plan covers the remaining concrete WooPayments Product Add-ons compatibility adapter, including frontend, AJAX, cart display, product price update, order meta, and conversion-suppression behavior. WPCOM remains read-only.
- Placeholder scan: No TODO/TBD placeholders remain.
- Type consistency: Class names, test names, hook names, and file paths align with the existing native multi-currency compatibility pattern.
