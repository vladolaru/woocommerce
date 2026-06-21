# Native Multi-Currency Switcher Widget Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the preserved WooPayments legacy multi-currency switcher widget from WooCommerce core when the native runtime owns multi-currency.

**Architecture:** Add a runtime-gated `MultiCurrencySwitcherWidgetController` that hooks `widgets_init`, creates a native `WC_Widget` subclass, and delegates rendered markup to the existing `MultiCurrencySwitcherProjectionService`. Use the B2w compatibility controller for switching-disable decisions. Keep the dynamic block/editor script migration out of this slice because the core asset does not exist yet.

**Tech Stack:** WooCommerce PHP, WordPress widgets, native HTML `<select>`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPCS, PHPStan.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidget.php`
    - `WC_Widget` subclass preserving widget ID `currency_switcher_widget`.
    - Sentence-case WooCommerce labels and settings.
    - `widget()` delegates to `MultiCurrencySwitcherProjectionService::get_widget_markup()`.
    - Uses `MultiCurrencyCompatibilityController::should_disable_currency_switching()` for switch-disable behavior.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php`
    - `RegisterHooksInterface` implementation.
    - Runtime-gated `widgets_init` registration.
    - Creates and registers exactly one widget instance for this controller.
    - Test injection setter for `MultiCurrencySwitcherProjectionService`.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetControllerTest.php`
    - Covers runtime gating, widget registration, widget metadata, widget rendering, query-param preservation, switch-disable propagation, and bootstrap registration.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller with the existing native multi-currency controllers.
- Add `plugins/woocommerce/changelog/add-native-payments-b2x-multi-currency-switcher-widget`
    - Patch changelog entry.

## Task 1: Switcher Widget Red Test

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetControllerTest.php`

- [ ] **Step 1: Write the failing PHPUnit coverage**

Create a test class that imports `MultiCurrencySwitcherWidget`, `MultiCurrencySwitcherWidgetController`, `MultiCurrencyCompatibilityController`, `MultiCurrencyRuntimeArbiter`, and `MultiCurrencySwitcherProjectionService`.

The tests must cover these behaviors:

```php
public function test_does_not_register_widget_hook_when_plugin_owns_runtime(): void;
public function test_registers_widget_hook_when_core_owns_runtime(): void;
public function test_registers_single_widget_instance_on_widgets_init(): void;
public function test_widget_exposes_preserved_metadata_and_sentence_case_settings(): void;
public function test_widget_renders_projection_markup_with_query_args(): void;
public function test_widget_passes_switching_disabled_decision_to_projection(): void;
public function test_bootstrap_registers_switcher_widget_controller(): void;
```

Use a fake projection service that records the widget instance settings, wrapper args, query args, and switching-disabled flag passed to `get_widget_markup()`. Use a compatibility controller test double that returns a deterministic switching-disabled value.

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySwitcherWidgetControllerTest
```

Expected before production code:

```text
ERRORS!
Tests: 7
Class "Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherWidgetController" not found
```

## Task 2: Widget And Runtime-Gated Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidget.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the widget class**

Implement `MultiCurrencySwitcherWidget extends \WC_Widget` with:

```php
private MultiCurrencySwitcherProjectionService $switcher_projection_service;
private MultiCurrencyCompatibilityController $compatibility_controller;
```

Constructor signature:

```php
public function __construct(
    MultiCurrencySwitcherProjectionService $switcher_projection_service,
    MultiCurrencyCompatibilityController $compatibility_controller
);
```

Constructor metadata:

```php
$this->widget_cssclass    = 'woocommerce widget_currency_switcher';
$this->widget_description = __( 'Let customers switch between enabled currencies.', 'woocommerce' );
$this->widget_id          = 'currency_switcher_widget';
$this->widget_name        = __( 'Currency switcher widget', 'woocommerce' );
$this->settings           = array(
    'title'  => array(
        'type'  => 'text',
        'std'   => '',
        'label' => __( 'Title', 'woocommerce' ),
    ),
    'symbol' => array(
        'type'  => 'checkbox',
        'std'   => true,
        'label' => __( 'Display currency symbols', 'woocommerce' ),
    ),
    'flag'   => array(
        'type'  => 'checkbox',
        'std'   => false,
        'label' => __( 'Display flags on supported devices', 'woocommerce' ),
    ),
);
```

`widget( $args, $instance )` must echo:

```php
$this->switcher_projection_service->get_widget_markup(
    is_array( $instance ) ? $instance : array(),
    is_array( $args ) ? $args : array(),
    $this->get_current_query_args(),
    $this->compatibility_controller->should_disable_currency_switching()
);
```

`get_current_query_args()` must return a sanitized copy of `$_GET` using `wp_unslash()` and `wc_clean()`, with a nonce-ignore comment because this is read-only query preservation for the switcher form.

- [ ] **Step 2: Add the controller class**

Implement `MultiCurrencySwitcherWidgetController implements RegisterHooksInterface` with:

```php
private MultiCurrencyRuntimeArbiter $arbiter;
private MultiCurrencyCompatibilityController $compatibility_controller;
private ?MultiCurrencySwitcherProjectionService $switcher_projection_service = null;
private ?MultiCurrencySwitcherWidget $widget = null;

final public function init(
    MultiCurrencyRuntimeArbiter $arbiter,
    MultiCurrencyCompatibilityController $compatibility_controller
): void;
public function set_switcher_projection_service( MultiCurrencySwitcherProjectionService $switcher_projection_service ): void;
public function register();
public function handle_widgets_init(): void;
public function get_registered_widget(): ?MultiCurrencySwitcherWidget;
```

`register()` must only add `widgets_init` when `$this->arbiter->should_core_register()` is true, and must not register duplicate callbacks for the same controller instance.

`handle_widgets_init()` must create one `MultiCurrencySwitcherWidget` instance and pass it to `register_widget()`. If the widget instance already exists, return without creating or registering a second instance.

Default `get_switcher_projection_service()` should follow existing multi-currency controller patterns by creating `MultiCurrencyLocalizationService`, `CurrencyRateProviderRegistry`, `MultiCurrencyRateService`, `MultiCurrencyDatabaseCache`, `MultiCurrencyStateBuilder`, and `MultiCurrencySwitcherProjectionService`.

- [ ] **Step 3: Bootstrap the controller**

Add the controller to the native multi-currency bootstrap section in `plugins/woocommerce/includes/class-woocommerce.php` near the Storefront switcher integration:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherWidgetController::class )->register();
```

Place it after `MultiCurrencyCompatibilityController` and before `MultiCurrencyStorefrontIntegrationController`.

## Task 3: Changelog And Verification

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2x-multi-currency-switcher-widget`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

```text
Significance: patch
Type: fix
Comment: Add native multi-currency switcher widget registration.
```

- [ ] **Step 2: Run focused verification**

Create a temporary PHPStan config in `$TMPDIR`:

```bash
mkdir -p "$TMPDIR"
printf '%s\n' \
  'includes:' \
  '  - /Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/phpstan.neon' \
  'parameters:' \
  '  reportUnmatchedIgnoredErrors: false' \
  > "$TMPDIR/phpstan-b2x.neon"
```

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySwitcherWidgetControllerTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyCompatibilityControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencySwitcherWidget.php src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php --configuration "$TMPDIR/phpstan-b2x.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencySwitcherWidget.php src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetControllerTest.php
```

Run from the repo root:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2x.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage and commit only source/test/changelog**

Stage:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidget.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySwitcherWidgetControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2x-multi-currency-switcher-widget
```

Run:

```bash
git diff --cached --check
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
```

Commit source/test/bootstrap separately from the changelog if following the WooCommerce git-commit split:

```bash
git restore --staged plugins/woocommerce/changelog/add-native-payments-b2x-multi-currency-switcher-widget
git commit -m "fix(payments): add multi-currency switcher widget"
git add plugins/woocommerce/changelog/add-native-payments-b2x-multi-currency-switcher-widget
git commit -m "chore(payments): add multi-currency widget changelog"
```

Do not stage or commit `.agents/*`, `docs/superpowers/*`, or the external staging log.

## Self-Review

- Spec coverage: B2x covers the legacy switcher widget registration and rendering path using native PHP only. Dynamic block registration/editor assets and settings/admin assets remain intentionally deferred.
- Placeholder scan: No placeholder tasks remain.
- Type consistency: Controller, widget class, method names, widget ID, settings keys, hook name, and bootstrap path match existing native multi-currency services and the WooPayments widget reference.
