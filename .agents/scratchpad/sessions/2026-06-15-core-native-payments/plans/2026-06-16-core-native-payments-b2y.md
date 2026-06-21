# Native Multi-Currency Switcher Block Render Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the preserved WooPayments multi-currency switcher block type from WooCommerce core for server-side rendering when the native runtime owns multi-currency.

**Architecture:** Add a runtime-gated `MultiCurrencySwitcherBlockController` that registers `woocommerce-payments/multi-currency-switcher` on `init` with the preserved attributes and a PHP render callback. Delegate rendered markup to `MultiCurrencySwitcherProjectionService::get_block_markup()` and switching-disable decisions to `MultiCurrencyCompatibilityController`. Do not add an `editor_script` in this slice because the core editor asset is not migrated yet.

**Tech Stack:** WooCommerce PHP, WordPress block registration, native HTML `<select>` via projection service, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPCS, PHPStan.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php`
    - `RegisterHooksInterface` implementation.
    - Runtime-gated `init` hook registration.
    - Server-side block registration for `woocommerce-payments/multi-currency-switcher`.
    - Render callback delegating to `MultiCurrencySwitcherProjectionService`.
    - Test injection setter for `MultiCurrencySwitcherProjectionService`.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockControllerTest.php`
    - Covers runtime gating, block registration, preserved attributes, omitted editor script, render delegation, switching-disable propagation, and bootstrap registration.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller with the existing native multi-currency controllers.
- Add `plugins/woocommerce/changelog/add-native-payments-b2y-multi-currency-switcher-block`
    - Patch changelog entry.

## Task 1: Switcher Block Red Test

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockControllerTest.php`

- [x] **Step 1: Write the failing PHPUnit coverage**

Create a test class that imports `MultiCurrencySwitcherBlockController`, `MultiCurrencyCompatibilityController`, `MultiCurrencyRuntimeArbiter`, `MultiCurrencySwitcherProjectionService`, and `WP_Block_Type_Registry`.

The tests must cover these behaviors:

```php
public function test_does_not_register_init_hook_when_plugin_owns_runtime(): void;
public function test_registers_init_hook_when_core_owns_runtime(): void;
public function test_registers_switcher_block_type_with_preserved_attributes(): void;
public function test_render_callback_delegates_to_projection_service(): void;
public function test_render_callback_passes_switching_disabled_decision(): void;
public function test_bootstrap_registers_switcher_block_controller(): void;
```

Use a fake projection service that records block attributes, sanitized query args, and switching-disabled flag passed to `get_block_markup()`. Use a compatibility controller test double that returns a deterministic switching-disabled value. Clean up the block registry in `tearDown()` by unregistering `woocommerce-payments/multi-currency-switcher` when present.

- [x] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySwitcherBlockControllerTest
```

Expected before production code:

```text
ERRORS!
Tests: 6
Class "Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherBlockController" not found
```

## Task 2: Runtime-Gated Block Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [x] **Step 1: Add the controller class**

Implement `MultiCurrencySwitcherBlockController implements RegisterHooksInterface` with:

```php
private const BLOCK_NAME = 'woocommerce-payments/multi-currency-switcher';

private MultiCurrencyRuntimeArbiter $arbiter;
private MultiCurrencyCompatibilityController $compatibility_controller;
private ?MultiCurrencySwitcherProjectionService $switcher_projection_service = null;

final public function init(
    MultiCurrencyRuntimeArbiter $arbiter,
    MultiCurrencyCompatibilityController $compatibility_controller
): void;
public function set_switcher_projection_service( MultiCurrencySwitcherProjectionService $switcher_projection_service ): void;
public function register();
public function handle_init(): void;
public function render_block_widget( $block_attributes ): string;
```

`register()` must only add `init` when `$this->arbiter->should_core_register()` is true, and must not register duplicate callbacks for the same controller instance.

- [x] **Step 2: Register the block type**

`handle_init()` must call `register_block_type()` for `woocommerce-payments/multi-currency-switcher` with:

```php
array(
    'api_version'     => 3,
    'render_callback' => array( $this, 'render_block_widget' ),
    'attributes'      => array(
        'symbol'          => array(
            'type'    => 'boolean',
            'default' => true,
        ),
        'flag'            => array(
            'type'    => 'boolean',
            'default' => false,
        ),
        'fontSize'        => array(
            'type'    => 'integer',
            'default' => 14,
        ),
        'fontLineHeight'  => array(
            'type'    => 'number',
            'default' => 1.5,
        ),
        'fontColor'       => array(
            'type'    => 'string',
            'default' => '#000000',
        ),
        'border'          => array(
            'type'    => 'boolean',
            'default' => true,
        ),
        'borderRadius'    => array(
            'type'    => 'integer',
            'default' => 3,
        ),
        'borderColor'     => array(
            'type'    => 'string',
            'default' => '#000000',
        ),
        'backgroundColor' => array(
            'type'    => 'string',
            'default' => 'transparent',
        ),
    ),
)
```

Do not set `editor_script` in this slice. This intentionally supports server rendering for existing content while deferring editor inserter/script parity.

- [x] **Step 3: Add the render callback**

`render_block_widget( $block_attributes )` must return:

```php
$this->get_switcher_projection_service()->get_block_markup(
    is_array( $block_attributes ) ? $block_attributes : array(),
    $this->get_current_query_args(),
    $this->compatibility_controller->should_disable_currency_switching()
);
```

`get_current_query_args()` must return a sanitized copy of `$_GET` using `wp_unslash()` and `wc_clean()`, with a nonce-ignore comment because this is read-only query preservation for the switcher form.

Default `get_switcher_projection_service()` should follow existing multi-currency controller patterns by creating `MultiCurrencyLocalizationService`, `CurrencyRateProviderRegistry`, `MultiCurrencyRateService`, `MultiCurrencyDatabaseCache`, `MultiCurrencyStateBuilder`, and `MultiCurrencySwitcherProjectionService`.

- [x] **Step 4: Bootstrap the controller**

Add the controller to the native multi-currency bootstrap section in `plugins/woocommerce/includes/class-woocommerce.php` near the switcher widget controller:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherBlockController::class )->register();
```

Place it after `MultiCurrencySwitcherWidgetController` and before `MultiCurrencyStorefrontIntegrationController`.

## Task 3: Changelog And Verification

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2y-multi-currency-switcher-block`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [x] **Step 1: Add changelog**

```text
Significance: patch
Type: fix
Comment: Add native multi-currency switcher block rendering.
```

- [x] **Step 2: Run focused verification**

Create a temporary PHPStan config in `$TMPDIR`:

```bash
mkdir -p "$TMPDIR"
printf '%s\n' \
  'includes:' \
  '  - /Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/phpstan.neon' \
  'parameters:' \
  '  reportUnmatchedIgnoredErrors: false' \
  > "$TMPDIR/phpstan-b2y.neon"
```

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySwitcherBlockControllerTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyCompatibilityControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php --configuration "$TMPDIR/phpstan-b2y.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockControllerTest.php
```

Run from the repo root:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2y.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [x] **Step 3: Stage and commit only source/test/changelog**

Stage:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherBlockControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2y-multi-currency-switcher-block
```

Run:

```bash
git diff --cached --check
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
```

Commit source/test/bootstrap separately from the changelog if following the WooCommerce git-commit split:

```bash
git restore --staged plugins/woocommerce/changelog/add-native-payments-b2y-multi-currency-switcher-block
git commit -m "fix(payments): add multi-currency switcher block render"
git add plugins/woocommerce/changelog/add-native-payments-b2y-multi-currency-switcher-block
git commit -m "chore(payments): add multi-currency block changelog"
```

Do not stage or commit `.agents/*`, `docs/superpowers/*`, or the external staging log.

## Self-Review

- Spec coverage: B2y covers the server-rendered dynamic block registration and explicitly defers editor script/inserter parity until the asset migration exists.
- Placeholder scan: No placeholder tasks remain.
- Type consistency: Controller, method names, block name, attributes, hook name, and bootstrap path match the WooPayments block reference and existing native switcher projection service.
