# Core Native Payments B2s Admin Notices Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register native multi-currency admin notices and notice dismissal when core owns multi-currency.

**Architecture:** Add a `MultiCurrencyAdminNoticesController` that gates WooPayments-compatible admin notice hooks behind `MultiCurrencyRuntimeArbiter::should_core_register()`. The controller delegates notice payload, markup, and dismissal intent to `MultiCurrencyAdminNoticeProjectionService`, while it owns WordPress capability, nonce, option update, and output integration.

**Tech Stack:** WooCommerce core PHP, WordPress admin hooks/nonces/options, PHPUnit through `pnpm test:php:env`, PHPCS/PHPStan.

---

## Files

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoticesController.php`
    - Runtime-gated registration for `admin_notices` and `wp_loaded`.
    - Notice rendering for the preserved manual-rate store-currency-changed notice.
    - Dismissal handling for `wcpay-multi-currency-hide-notice=currency_changed`.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoticesControllerTest.php`
    - TDD coverage for runtime registration, notice output, capability gating, valid dismissal, and invalid dismissal errors through an injectable die handler.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the admin notices controller from WooCommerce bootstrap beside other native multi-currency controllers.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2s-multi-currency-admin-notices`
    - Record the admin notice hook parity fix.

## Task 1: Write Failing Controller Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoticesControllerTest.php`

- [ ] **Step 1: Create the test class**

Use `WC_Unit_Test_Case`, clear `admin_notices` and `wp_loaded` hooks in teardown, clean `$_GET`, restore the notice option, and create a static `MultiCurrencyRuntimeArbiter` test double.

- [ ] **Step 2: Add registration tests**

Assert that plugin-owned runtime registers nothing:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

$sut->register();

$this->assertFalse( has_action( 'admin_notices', array( $sut, 'handle_admin_notices' ) ) );
$this->assertFalse( has_action( 'wp_loaded', array( $sut, 'handle_wp_loaded' ) ) );
```

Assert that core-owned runtime registers both preserved hooks once:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

$sut->register();
$sut->register();

$this->assertSame( 10, has_action( 'admin_notices', array( $sut, 'handle_admin_notices' ) ) );
$this->assertSame( 10, has_action( 'wp_loaded', array( $sut, 'handle_wp_loaded' ) ) );
```

- [ ] **Step 3: Add notice rendering tests**

Create an administrator user, set `wcpay_multi_currency_show_store_currency_changed_notice` to `array( 'Canadian dollar', 'Euro' )`, call `handle_admin_notices()` inside output buffering, and assert the warning markup contains:

```text
The store currency was recently changed. The following currencies are set to manual rates and may need updates: Canadian dollar, Euro
```

Assert that the dismiss link contains `wcpay-multi-currency-hide-notice=currency_changed` and `_wcpay_multi_currency_notice_nonce`.

Also set a non-admin current user and assert no notice markup is emitted.

- [ ] **Step 4: Add valid dismissal test**

Populate `$_GET` with `wcpay-multi-currency-hide-notice=currency_changed` and a valid nonce generated with `wp_create_nonce( 'wcpay_multi_currency_hide_notices_nonce' )`, then call `handle_wp_loaded()` as an administrator. Assert `wcpay_multi_currency_show_store_currency_changed_notice` becomes `no`.

- [ ] **Step 5: Add invalid dismissal tests**

Inject a die handler that records the message and throws `RuntimeException`. With an administrator and an invalid nonce, assert `handle_wp_loaded()` throws and records `Action failed. Please refresh the page and retry.`. With a valid nonce and a non-admin user, assert it throws and records the forbidden message.

- [ ] **Step 6: Run the focused test to verify RED**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAdminNoticesControllerTest
```

Expected: fail because `MultiCurrencyAdminNoticesController` does not exist yet.

## Task 2: Implement The Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoticesController.php`

- [ ] **Step 1: Add controller skeleton**

Implement `RegisterHooksInterface`, inject `MultiCurrencyRuntimeArbiter` through `init()`, and add an internal `set_die_handler()` test hook.

- [ ] **Step 2: Register the preserved hooks**

Register only when core owns multi-currency:

```php
if ( ! $this->arbiter->should_core_register() ) {
	return;
}

$this->add_action_once( 'admin_notices', array( $this, 'handle_admin_notices' ) );
$this->add_action_once( 'wp_loaded', array( $this, 'handle_wp_loaded' ) );
```

- [ ] **Step 3: Implement notice rendering**

Read `wcpay_multi_currency_show_store_currency_changed_notice`, call `MultiCurrencyAdminNoticeProjectionService::get_notices_for_user( current_user_can( 'manage_woocommerce' ), $manual_rate_currencies )`, build the dismiss URL with `wp_nonce_url()`, and echo `get_notice_markup()` for each projected notice.

- [ ] **Step 4: Implement dismissal handling**

Call `MultiCurrencyAdminNoticeProjectionService::get_hide_notice_intent()` with sanitized `$_GET`, a nonce-valid boolean, and the current capability check. On `invalid_nonce`, call the die handler with `__( 'Action failed. Please refresh the page and retry.', 'woocommerce' )`. On `forbidden`, call the die handler with `__( 'Sorry, you are not allowed to do that.', 'woocommerce' )`. On a valid hide intent, call `update_option( $intent['option_name'], $intent['option_value'] )`.

- [ ] **Step 5: Run the focused test to verify GREEN**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAdminNoticesControllerTest
```

Expected: pass.

## Task 3: Bootstrap, Changelog, And Verification

**Files:**

- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Create: `plugins/woocommerce/changelog/add-native-payments-b2s-multi-currency-admin-notices`

- [ ] **Step 1: Register the controller in WooCommerce bootstrap**

Add the controller beside the other native multi-currency controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoticesController::class )->register();
```

- [ ] **Step 2: Add the changelog entry**

Use this entry:

```text
Significance: patch
Type: fix
Comment: Add native multi-currency admin notice hook registration.
```

- [ ] **Step 3: Run focused and regression checks**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAdminNoticesControllerTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyAdminNoticesController.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyAdminNoticesController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoticesControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2s.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 4: Commit only source, test, bootstrap, and changelog files**

Do not stage `docs/superpowers/`, `.agents/`, generated assets, or the external staging log.

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoticesController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoticesControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2s-multi-currency-admin-notices
git commit -m "fix(payments): add multi-currency admin notices"
```
