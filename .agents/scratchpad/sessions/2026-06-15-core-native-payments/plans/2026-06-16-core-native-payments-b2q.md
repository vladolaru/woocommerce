# Core Native Payments B2q Async Price Renderer Hooks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register native multi-currency async price renderer hooks when core owns multi-currency and cache-optimized rendering is active.

**Architecture:** Add a small `MultiCurrencyAsyncPriceRendererController` that applies the existing async price projection service at hook time. The controller mirrors WooPayments activation gates: core runtime ownership, frontend request, cache-optimized mode, automatic currency switching, no pending explicit currency switch, no active WooCommerce session, and no Store API/admin/cron context. Add the renderer as tracked classic asset source under `client/legacy`; generated `assets/` outputs are produced by the build and remain out of the commit unless already tracked.

**Tech Stack:** WooCommerce core PHP, WordPress hooks/scripts, PHPUnit through `pnpm test:php:env`, PHPCS/PHPStan.

---

## Files

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php`
    - Runtime-gated hook registration for `wc_price`, sale/range screen-reader annotations, and `wp_enqueue_scripts`.
    - Hook callbacks delegate markup/config projection to `MultiCurrencyAsyncPriceProjectionService`.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererControllerTest.php`
    - TDD coverage for registration gates, markup callbacks, and enqueue/localization behavior.
- Create: `plugins/woocommerce/client/legacy/js/frontend/multi-currency-async-renderer.js`
    - Classic storefront renderer source copied into `assets/js/frontend/` by the classic-assets build.
- Create: `plugins/woocommerce/client/legacy/js/frontend/test/multi-currency-async-renderer.test.js`
    - Jest coverage for conversion, fallback rendering, and screen-reader text updates.
- Create: `plugins/woocommerce/client/legacy/css/multi-currency-async-renderer.scss`
    - Skeleton/error styles copied into `assets/css/` by the classic-assets build.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the controller from WooCommerce bootstrap beside the other native multi-currency controllers.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2q-multi-currency-async-renderer`
    - Record the async price renderer hook parity fix.

## Task 1: Write Failing Renderer Asset Tests

**Files:**

- Create: `plugins/woocommerce/client/legacy/js/frontend/test/multi-currency-async-renderer.test.js`

- [ ] **Step 1: Add Jest tests for core renderer behavior**

Assert that the renderer converts skeleton markup into WooCommerce-compatible `<bdi>` markup, removes the SSR placeholder on success, preserves placeholder text on hard fallback, and updates annotated sale/range screen-reader text from localized templates.

- [ ] **Step 2: Run the focused JS test to verify RED**

```bash
pnpm --dir plugins/woocommerce/client/legacy test:js -- multi-currency-async-renderer.test.js
```

Expected: fail because `../multi-currency-async-renderer` does not exist yet.

## Task 2: Write Failing Controller Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererControllerTest.php`

- [ ] **Step 1: Create the test class**

Use `WC_Unit_Test_Case`, a `$sut`-style controller helper, and test doubles for `MultiCurrencyRuntimeArbiter`, `MultiCurrencyRequestContext`, and `MultiCurrencyFrontendProjectionService`.

- [ ] **Step 2: Add registration gate tests**

Cover these expected outcomes:

```php
$this->assertFalse( has_filter( 'wc_price', array( $sut, 'handle_wc_price' ) ) );
$this->assertSame( 999, has_filter( 'wc_price', array( $sut, 'handle_wc_price' ) ) );
$this->assertSame( 10, has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
```

Required blockers: plugin-owned runtime, inactive cache mode, disabled auto currency switching, active WooCommerce session, pending `currency` query arg, and Store API request.

- [ ] **Step 3: Add callback behavior tests**

Assert that `handle_wc_price()` wraps the original HTML with `wcpay-async-price` skeleton markup, preserves the original formatted price for the screen-reader fallback, and honors `wcpay_multi_currency_async_price_type`.

Assert that sale and range callbacks add the expected `data-wcpay-sr-*` attributes by delegating to the projection service.

- [ ] **Step 4: Add enqueue/localization tests**

Assert that `handle_wp_enqueue_scripts()` registers/enqueues the `wcpay-multi-currency-async-renderer` handle, localizes `wcpayAsyncPriceConfig`, and registers/enqueues the paired style handle only when the controller exposes a non-empty style URL.

- [ ] **Step 5: Run the focused test to verify RED**

```bash
pnpm test:php:env -- --filter MultiCurrencyAsyncPriceRendererControllerTest
```

Expected: fail because `MultiCurrencyAsyncPriceRendererController` does not exist yet.

## Task 3: Implement The Renderer Asset Source

**Files:**

- Create: `plugins/woocommerce/client/legacy/js/frontend/multi-currency-async-renderer.js`
- Create: `plugins/woocommerce/client/legacy/css/multi-currency-async-renderer.scss`

- [ ] **Step 1: Implement a classic frontend renderer**

Use a browser-safe IIFE with CommonJS export for Jest. Read `window.wcpayAsyncPriceConfig`, fetch the public config route, cache config in `sessionStorage`, convert skeleton-wrapped prices, sync currency switchers, observe dynamic price markup, and keep fallback screen-reader text available if conversion fails.

- [ ] **Step 2: Implement skeleton styles**

Port the WooPayments skeleton shimmer/error styles, including `prefers-reduced-motion: reduce`.

- [ ] **Step 3: Run JS tests and classic asset lint/build**

```bash
pnpm --dir plugins/woocommerce/client/legacy test:js -- multi-currency-async-renderer.test.js
pnpm --dir plugins/woocommerce/client/legacy lint:lang:js
pnpm --dir plugins/woocommerce/client/legacy lint:lang:css
pnpm --dir plugins/woocommerce/client/legacy build:project
```

## Task 4: Implement The Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php`

- [ ] **Step 1: Add the controller skeleton**

Implement `RegisterHooksInterface`, inject `MultiCurrencyRuntimeArbiter` through `init()`, and add internal setters for request context, frontend projection service, active-session resolver, and style asset data.

- [ ] **Step 2: Implement activation gates**

`register()` should return early unless all of these are true:

```php
$this->arbiter->should_core_register();
$this->get_request_context()->should_register_frontend_hooks();
! $this->get_request_context()->is_store_api_request();
$this->is_using_auto_currency_switching();
! $this->has_pending_currency_switch();
MultiCurrencyAsyncPriceProjectionService::should_activate(
	$this->get_frontend_projection_service()->is_cache_optimized_mode(),
	is_admin(),
	wp_doing_cron(),
	$this->get_request_context()->is_admin_api_request(),
	$this->has_active_session()
);
```

- [ ] **Step 3: Register hooks once**

Register the WooPayments-compatible hooks:

```php
$this->add_filter_once( 'wc_price', array( $this, 'handle_wc_price' ), 999, 5 );
$this->add_filter_once( 'woocommerce_format_sale_price', array( $this, 'handle_woocommerce_format_sale_price' ), 999, 3 );
$this->add_filter_once( 'woocommerce_format_price_range', array( $this, 'handle_woocommerce_format_price_range' ), 999, 3 );
$this->add_action_once( 'wp_enqueue_scripts', array( $this, 'handle_wp_enqueue_scripts' ) );
```

- [ ] **Step 4: Implement callbacks**

Callbacks should delegate to `MultiCurrencyAsyncPriceProjectionService` and expose the existing WooPayments filter `wcpay_multi_currency_async_price_type` with a new WooCommerce hook docblock.

- [ ] **Step 5: Implement enqueue/localization**

Build the asset manifest from the projection service and use it to register/enqueue `assets/js/frontend/multi-currency-async-renderer{.min}.js`, localize `wcpayAsyncPriceConfig`, and enqueue `assets/css/multi-currency-async-renderer.css`.

## Task 5: Bootstrap, Changelog, And Verification

**Files:**

- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Create: `plugins/woocommerce/changelog/add-native-payments-b2q-multi-currency-async-renderer`

- [ ] **Step 1: Register the controller in WooCommerce bootstrap**

Add the controller beside the other native multi-currency controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAsyncPriceRendererController::class )->register();
```

- [ ] **Step 2: Add the changelog entry**

Use a patch-significance fix entry:

```text
Significance: patch
Type: fix
Comment: Add native multi-currency async price renderer hook registration.
```

- [ ] **Step 3: Run focused and regression checks**

```bash
pnpm test:php:env -- --filter "MultiCurrencyAsyncPriceRendererControllerTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest"
composer exec -- phpstan analyse src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererControllerTest.php --memory-limit=2G
pnpm exec -- phpcs src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --dir plugins/woocommerce/client/legacy test:js -- multi-currency-async-renderer.test.js
pnpm --dir plugins/woocommerce/client/legacy lint:lang:js
pnpm --dir plugins/woocommerce/client/legacy lint:lang:css
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2q.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 4: Commit only source/test/changelog files**

Do not stage `docs/superpowers/` or `.agents/`. Commit with:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAsyncPriceRendererControllerTest.php plugins/woocommerce/client/legacy/js/frontend/multi-currency-async-renderer.js plugins/woocommerce/client/legacy/js/frontend/test/multi-currency-async-renderer.test.js plugins/woocommerce/client/legacy/css/multi-currency-async-renderer.scss plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2q-multi-currency-async-renderer
git commit -m "fix(payments): add multi-currency async renderer hooks"
```
