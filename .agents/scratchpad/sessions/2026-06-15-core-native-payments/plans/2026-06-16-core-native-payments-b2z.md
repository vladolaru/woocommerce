# Native Multi-Currency Settings Shell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the native multi-currency WooCommerce settings tab and server-rendered callbacks when the core runtime owns multi-currency.

**Architecture:** Add a runtime-gated `MultiCurrencySettingsController` plus a small `MultiCurrencySettingsPage` adapter for WooCommerce's classic settings API. The controller registers the settings page and field callbacks from `MultiCurrencySettingsProjectionService`, uses injectable provider/onboarding/asset resolvers for transition-time seams, and skips enqueueing the missing React settings bundle until the asset is migrated into core.

**Tech Stack:** WooCommerce PHP settings API, native multi-currency projection services, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsPage.php`
    - Minimal `WC_Settings_Page` subclass created from a projected settings manifest.
    - Returns connected settings rows or onboarding CTA rows.
    - Hides the classic save button when the projection says the page owns rendering.
- Create `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php`
    - `RegisterHooksInterface` implementation.
    - Runtime-gated registration for `woocommerce_get_settings_pages`, `admin_print_scripts`, the custom WooCommerce settings field callbacks, `admin_enqueue_scripts`, and WCPay JS config flag projection.
    - Resolver setters for provider connected state, onboarding URL, current tab/screen, asset availability, and asset registration/enqueue operations.
- Create `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php`
    - Covers runtime gating, settings page registration, connected/disconnected page modes, custom field output, emoji script page guard, guarded asset enqueue, JS config flag, and bootstrap registration.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller with the existing native multi-currency controllers.
- Add `plugins/woocommerce/changelog/add-native-payments-b2z-multi-currency-settings-shell`
    - Patch changelog entry.

## Task 1: Settings Controller Red Test

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php`

- [ ] **Step 1: Write the failing PHPUnit coverage**

Create a test class that imports `MultiCurrencyRuntimeArbiter`,
`MultiCurrencySettingsController`, `MultiCurrencySettingsPage`, and
`WC_Unit_Test_Case`.

The tests must cover these behaviors:

```php
public function test_does_not_register_hooks_when_plugin_owns_runtime(): void;
public function test_registers_settings_hooks_when_core_owns_runtime(): void;
public function test_registers_connected_settings_page(): void;
public function test_registers_onboarding_cta_settings_page_when_provider_is_disconnected(): void;
public function test_renders_settings_container_and_hides_save_button(): void;
public function test_renders_onboarding_cta_from_resolver(): void;
public function test_prints_emoji_detection_script_only_on_settings_page(): void;
public function test_skips_admin_asset_enqueue_when_bundle_is_missing(): void;
public function test_enqueues_admin_assets_when_bundle_is_available(): void;
public function test_adds_multi_currency_flag_to_wcpay_js_config(): void;
public function test_bootstrap_registers_settings_controller(): void;
```

Use a static arbiter test double that returns either
`MultiCurrencyRuntimeArbiter::OWNER_PLUGIN` or
`MultiCurrencyRuntimeArbiter::OWNER_CORE`. Use resolver setters on the
controller for:

```php
$controller->set_provider_connected_resolver( fn() => true );
$controller->set_onboarding_url_resolver( fn() => 'https://example.test/onboarding' );
$controller->set_current_tab_resolver( fn() => 'wcpay_multi_currency' );
$controller->set_current_screen_base_resolver( fn() => 'woocommerce_page_wc-settings' );
$controller->set_admin_request_resolver( fn() => true );
$controller->set_asset_available_resolver( fn() => false );
```

Use recorder callbacks for asset registration and enqueue assertions:

```php
$registered_assets = array();
$enqueued_assets   = array();

$controller->set_asset_registrar(
    function ( array $manifest ) use ( &$registered_assets ): void {
        $registered_assets[] = $manifest;
    }
);
$controller->set_asset_enqueuer(
    function ( string $handle ) use ( &$enqueued_assets ): void {
        $enqueued_assets[] = $handle;
    }
);
```

Clean up global state in `tearDown()`:

```php
remove_all_filters( 'woocommerce_get_settings_pages' );
remove_all_filters( 'wcpay_settings' );
remove_all_actions( 'admin_print_scripts' );
remove_all_actions( 'woocommerce_admin_field_wcpay_multi_currency_settings_page' );
remove_all_actions( 'woocommerce_admin_field_wcpay_currencies_settings_onboarding_cta' );
remove_all_actions( 'admin_enqueue_scripts' );
unset( $GLOBALS['hide_save_button'] );
```

- [ ] **Step 2: Verify RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySettingsControllerTest
```

Expected before production code:

```text
ERRORS!
Class "Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsController" not found
Class "Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsPage" not found
```

## Task 2: Settings Page Adapter

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsPage.php`

- [ ] **Step 1: Add the settings page adapter**

Implement `MultiCurrencySettingsPage extends \WC_Settings_Page` with a
constructor that accepts the projected manifest:

```php
/**
 * @param array{id:string,label:string,hide_save_button:bool,settings:array<int,array<string,mixed>>} $manifest Settings page manifest.
 */
public function __construct( array $manifest ) {
    $this->id               = (string) $manifest['id'];
    $this->label            = (string) $manifest['label'];
    $this->hide_save_button = (bool) $manifest['hide_save_button'];
    $this->settings         = $manifest['settings'];

    parent::__construct();
}
```

Add these properties:

```php
/**
 * Whether the settings page should hide the classic save button.
 *
 * @var bool
 */
private bool $hide_save_button = false;

/**
 * Projected settings rows.
 *
 * @var array<int,array<string,mixed>>
 */
private array $settings = array();
```

Because `WC_Settings_Page` may not be loaded when this class is autoloaded in
tests, load it before the class declaration if needed:

```php
if ( ! class_exists( '\WC_Settings_Page' ) && defined( 'WC_ABSPATH' ) ) {
    require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
}
```

- [ ] **Step 2: Return projected settings and hide the save button**

Add a WooCommerce-compatible `get_settings()` method:

```php
/**
 * Get settings array.
 *
 * @param string $current_section Section being shown.
 * @return array<int,array<string,mixed>>
 */
public function get_settings( $current_section = '' ) {
    unset( $current_section );

    if ( $this->hide_save_button ) {
        $GLOBALS['hide_save_button'] = true;
    }

    return $this->settings;
}
```

## Task 3: Runtime-Gated Settings Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add the controller class and injection seams**

Implement `MultiCurrencySettingsController implements RegisterHooksInterface`
with:

```php
private MultiCurrencyRuntimeArbiter $arbiter;

/** @var callable|null */
private $provider_connected_resolver = null;
/** @var callable|null */
private $onboarding_url_resolver = null;
/** @var callable|null */
private $admin_request_resolver = null;
/** @var callable|null */
private $current_tab_resolver = null;
/** @var callable|null */
private $current_screen_base_resolver = null;
/** @var callable|null */
private $asset_available_resolver = null;
/** @var callable|null */
private $asset_registrar = null;
/** @var callable|null */
private $asset_enqueuer = null;
```

Add `final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void`
and public setter methods for each resolver/callback. Each setter is marked
`@internal Used by tests and future explicit bootstrap definitions.`

- [ ] **Step 2: Register settings hooks only when core owns runtime**

`register()` must return early when `$this->arbiter->should_core_register()` is
false. Otherwise it must add these hooks once:

```php
$this->add_filter_once( 'woocommerce_get_settings_pages', array( $this, 'handle_woocommerce_get_settings_pages' ) );
$this->add_action_once( 'admin_print_scripts', array( $this, 'handle_admin_print_scripts' ) );
$this->add_action_once( 'woocommerce_admin_field_wcpay_multi_currency_settings_page', array( $this, 'render_settings_container' ) );
$this->add_action_once( 'woocommerce_admin_field_wcpay_currencies_settings_onboarding_cta', array( $this, 'render_onboarding_cta' ) );
$this->add_action_once( 'admin_enqueue_scripts', array( $this, 'handle_admin_enqueue_scripts' ) );
$this->add_filter_once( 'wcpay_settings', array( $this, 'add_multi_currency_settings_config' ) );
```

Use private `add_action_once()` and `add_filter_once()` helpers matching the
other multi-currency controllers.

- [ ] **Step 3: Register the projected settings page**

Add:

```php
/**
 * Register the native multi-currency settings page.
 *
 * @param array<int,mixed> $settings_pages Settings page objects.
 * @return array<int,mixed>
 */
public function handle_woocommerce_get_settings_pages( array $settings_pages ): array {
    $manifest = MultiCurrencySettingsProjectionService::get_settings_page_manifest(
        $this->is_provider_connected(),
        $this->is_cli_request(),
        $this->is_wpcom_jobs_request(),
        did_action( 'upgrader_process_complete' ) > 0
    );

    if ( empty( $manifest ) ) {
        return $settings_pages;
    }

    $settings_pages[] = new MultiCurrencySettingsPage( $manifest );

    return $settings_pages;
}
```

`is_cli_request()` checks `defined( 'WP_CLI' ) && WP_CLI`. `is_wpcom_jobs_request()`
checks `defined( 'WPCOM_JOBS' ) && WPCOM_JOBS`. `is_provider_connected()` uses
the resolver when present and otherwise returns `false` for now.

- [ ] **Step 4: Render settings callbacks**

Add:

```php
public function render_settings_container(): void {
    $GLOBALS['hide_save_button'] = true;
    echo wp_kses_post( MultiCurrencySettingsProjectionService::get_settings_container_markup() );
}

public function render_onboarding_cta(): void {
    echo wp_kses_post(
        MultiCurrencySettingsProjectionService::get_onboarding_cta_markup(
            $this->get_onboarding_url()
        )
    );
}
```

`get_onboarding_url()` uses the resolver when present and otherwise returns:

```php
admin_url( 'admin.php?page=wc-admin&path=/payments/onboarding' )
```

- [ ] **Step 5: Add page-guarded emoji callback and asset handling**

Add:

```php
public function handle_admin_print_scripts(): void {
    if ( ! $this->is_multi_currency_settings_page() ) {
        return;
    }

    print_emoji_detection_script();
}

public function handle_admin_enqueue_scripts(): void {
    if ( ! MultiCurrencySettingsProjectionService::should_enqueue_admin_assets( $this->get_current_tab() ) ) {
        return;
    }

    if ( ! $this->is_settings_asset_available() ) {
        return;
    }

    $manifest = MultiCurrencySettingsProjectionService::get_admin_asset_manifest();
    $this->register_admin_assets( $manifest );
    $this->enqueue_admin_asset( (string) $manifest['script']['handle'] );
    $this->enqueue_admin_asset( (string) $manifest['style']['handle'] );
}
```

`is_settings_asset_available()` must use the resolver when present and otherwise
return `false` until the React settings bundle is migrated into core. The
default register/enqueue helpers may call `wp_enqueue_script()` and
`wp_enqueue_style()` only after the availability guard passes.

- [ ] **Step 6: Add JS config flag projection**

Add:

```php
/**
 * Add native multi-currency props to WCPay JS settings.
 *
 * @param array<string,mixed> $config Existing WCPay settings config.
 * @return array<string,mixed>
 */
public function add_multi_currency_settings_config( array $config ): array {
    return MultiCurrencySettingsProjectionService::add_props_to_wcpay_js_config( $config );
}
```

- [ ] **Step 7: Bootstrap the controller**

Add this line after the compatibility controller in
`plugins/woocommerce/includes/class-woocommerce.php`:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsController::class )->register();
```

## Task 4: Changelog And Verification

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2z-multi-currency-settings-shell`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

```text
Significance: patch
Type: fix
Comment: Add native multi-currency settings page shell.
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
  > "$TMPDIR/phpstan-b2z.neon"
```

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySettingsControllerTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencySettingsController.php src/Internal/MultiCurrency/MultiCurrencySettingsPage.php --configuration "$TMPDIR/phpstan-b2z.neon" --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencySettingsController.php src/Internal/MultiCurrency/MultiCurrencySettingsPage.php tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php
```

Run from the repo root:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2z.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage and commit only source/test/changelog**

Stage:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsPage.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php plugins/woocommerce/includes/class-woocommerce.php
```

Run:

```bash
git diff --cached --check
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
```

Commit:

```bash
git commit -m "fix(payments): add multi-currency settings shell"
```

Then stage and commit the changelog separately:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2z-multi-currency-settings-shell
git diff --cached --check
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
git commit -m "chore(payments): add multi-currency settings changelog"
```

## Self-Review

- Spec coverage: The plan covers settings tab/page registration, custom field callbacks, CTA rendering, emoji page guard, guarded asset enqueue, JS config flag, bootstrap registration, changelog, and verification.
- Placeholder scan: No TBD/TODO placeholders remain. The missing React settings bundle is explicitly deferred through an asset availability guard.
- Type consistency: Controller, settings page, resolver, callback, and hook names match the projection service and WooCommerce settings API names.
