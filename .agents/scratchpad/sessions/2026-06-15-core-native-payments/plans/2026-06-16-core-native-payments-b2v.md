# Native Multi-Currency REST Routes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the preserved WooPayments multi-currency REST routes from WooCommerce core when the native runtime owns multi-currency.

**Architecture:** Add a runtime-gated `MultiCurrencyRestController` that registers `/wc/v3/payments/multi-currency/*` routes on `rest_api_init`, delegates read-only response data to existing native state/frontend projection services, and owns the small option writes required by the preserved update endpoints. Keep settings-page and block-editor asset migration out of this slice.

**Tech Stack:** WooCommerce PHP, WordPress REST API, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPCS, PHPStan.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php`
    - `RegisterHooksInterface` implementation.
    - Extends `WP_REST_Controller`.
    - Runtime-gated `rest_api_init` action registration.
    - Route callbacks for store currencies, enabled-currency updates, single-currency settings, store settings, and public async config.
    - Test injection setters for `MultiCurrencyStateBuilder` and `MultiCurrencyFrontendProjectionService`.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRestControllerTest.php`
    - Covers runtime gating, route registration, permission callback, route responses, option writes, validation failures, and public config headers.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller with the existing native multi-currency controllers.
- Add `plugins/woocommerce/changelog/add-native-payments-b2v-multi-currency-rest-routes`
    - Patch changelog entry.

## Task 1: REST Controller Red Test

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRestControllerTest.php`

- [ ] **Step 1: Write the failing PHPUnit coverage**

Create a test class that imports `MultiCurrencyRestController`, `MultiCurrencyRuntimeArbiter`, `MultiCurrencyStateBuilder`, `MultiCurrencyFrontendProjectionService`, `MultiCurrencyLocalizationInterface`, `MultiCurrencyCurrency`, `MultiCurrencyState`, and WordPress REST classes.

The tests must cover these behaviors:

```php
public function test_does_not_register_rest_hooks_when_plugin_owns_runtime(): void;
public function test_registers_rest_hooks_and_routes_when_core_owns_runtime(): void;
public function test_omits_public_config_route_when_cache_mode_is_inactive(): void;
public function test_check_permission_requires_manage_woocommerce(): void;
public function test_returns_store_currencies_from_state_snapshot(): void;
public function test_updates_enabled_currencies_and_removes_removed_currency_settings(): void;
public function test_rejects_invalid_enabled_currency(): void;
public function test_reads_and_updates_single_currency_settings(): void;
public function test_rejects_invalid_manual_rate_for_single_currency_settings(): void;
public function test_reads_and_updates_store_settings(): void;
public function test_returns_public_config_with_cache_control_header(): void;
```

Use a fake state builder returning USD as default and EUR/GBP as additional available currencies. Use a fake frontend projection service that returns deterministic `get_settings()`, `get_single_currency_settings()`, `get_public_config()`, and `is_cache_optimized_mode()` values.

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRestControllerTest
```

Expected before production code:

```text
ERRORS!
Tests: 11
Class "Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController" not found
```

## Task 2: Runtime-Gated REST Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller class**

Implement `MultiCurrencyRestController` with:

```php
private const OPTION_PREFIX = 'wcpay_multi_currency';
private const REST_NAMESPACE = 'wc/v3';
private const REST_BASE = 'payments/multi-currency';
private const RENDERING_MODE_SPEED = 'speed';
private const RENDERING_MODE_CACHE = 'cache';
```

Core methods:

```php
final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void;
public function set_state_builder( MultiCurrencyStateBuilder $state_builder ): void;
public function set_frontend_projection_service( MultiCurrencyFrontendProjectionService $frontend_projection_service ): void;
public function register();
public function handle_rest_api_init(): void;
public function check_permission(): bool;
```

`register()` must only add `rest_api_init` when `$this->arbiter->should_core_register()` is true, and must not register duplicate callbacks for the same controller instance.

- [ ] **Step 2: Register the route manifest**

`handle_rest_api_init()` must call `MultiCurrencyRestProjectionService::get_route_manifest( $this->get_frontend_projection_service()->is_cache_optimized_mode() )` and register every route using `register_rest_route()`.

Map manifest callback markers to controller methods:

```php
'get_public_config' => array( $this, 'get_public_config' )
'get_store_currencies' => array( $this, 'get_store_currencies' )
'update_enabled_currencies' => array( $this, 'update_enabled_currencies' )
'get_single_currency_settings' => array( $this, 'get_single_currency_settings' )
'get_settings' => array( $this, 'get_settings' )
'update_single_currency_settings' => array( $this, 'update_single_currency_settings' )
'update_settings' => array( $this, 'update_settings' )
```

Use `__return_true` for the public route permission callback and `array( $this, 'check_permission' )` for all admin routes.

- [ ] **Step 3: Bootstrap the controller**

Add the controller to the native multi-currency bootstrap section in `plugins/woocommerce/includes/class-woocommerce.php` near the other multi-currency controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController::class )->register();
```

## Task 3: REST Callback Behavior

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php`

- [ ] **Step 1: Add read callbacks**

Implement:

```php
public function get_store_currencies();
public function get_single_currency_settings( \WP_REST_Request $request );
public function get_settings();
public function get_public_config();
```

Expected behavior:

- `get_store_currencies()` returns `available`, `enabled`, and `default` from `$this->get_state_builder()->build()`.
- `get_single_currency_settings()` delegates to `MultiCurrencyFrontendProjectionService::get_single_currency_settings()` and returns `WP_Error` when the projection throws.
- `get_settings()` delegates to `MultiCurrencyFrontendProjectionService::get_settings()`.
- `get_public_config()` delegates to `MultiCurrencyFrontendProjectionService::get_public_config()`, applies the `Cache-Control: private, max-age=300` header from `MultiCurrencyRestProjectionService::get_public_config_headers()`, and returns a `WP_REST_Response`.

- [ ] **Step 2: Add mutation callbacks**

Implement:

```php
public function update_enabled_currencies( \WP_REST_Request $request );
public function update_single_currency_settings( \WP_REST_Request $request );
public function update_settings( \WP_REST_Request $request );
```

Expected behavior:

- `update_enabled_currencies()` accepts an `enabled` array of currency codes, validates them against the current available currency codes, updates `wcpay_multi_currency_enabled_currencies`, removes the per-currency options for removed currencies, and returns the current store currencies response.
- `update_single_currency_settings()` validates `currency_code`, rejects manual rates that are non-numeric or less than or equal to zero, updates `exchange_rate`, `manual_rate`, `price_rounding`, and `price_charm` options using the preserved key names, and returns the single-currency settings response.
- `update_settings()` updates only `wcpay_multi_currency_enable_auto_currency`, `wcpay_multi_currency_enable_storefront_switcher`, and `wcpay_multi_currency_rendering_mode`; ignore invalid rendering-mode values and return `get_settings()`.

## Task 4: Changelog And Verification

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2v-multi-currency-rest-routes`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

```text
Significance: patch
Type: fix
Comment: Add native multi-currency REST route registration.
```

- [ ] **Step 2: Run focused verification**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRestControllerTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Run:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyRestController.php --configuration "$TMPDIR/phpstan-b2v.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyRestController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRestControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2v.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage and commit only source/test/changelog**

Stage:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRestController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRestControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2v-multi-currency-rest-routes
```

Run:

```bash
git diff --cached --check
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
```

Commit:

```bash
git commit -m "fix(payments): add multi-currency REST routes"
```

Do not stage or commit `.agents/*`, `docs/superpowers/*`, or the external staging log.

## Self-Review

- Spec coverage: B2v covers the missing PHP REST route registration and callbacks from `MultiCurrencyRestProjectionService`.
- Placeholder scan: No placeholder tasks remain.
- Type consistency: Controller, services, methods, route markers, and option keys match the existing native multi-currency services and WooPayments REST reference.
