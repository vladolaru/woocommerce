# Core Native Payments B2t Admin Note Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the native multi-currency WC Admin availability note when core owns multi-currency.

**Architecture:** Add a `MultiCurrencyAdminNoteController` that gates the preserved `admin_init` note-add path behind native multi-currency ownership and admin-request context. The controller delegates eligibility and note metadata to `MultiCurrencyAdminNoteProjectionService`, uses the native WooPayments provider as the default fail-closed connected-account signal, and creates a real WC Admin `Note` only after projection says the note should be added.

**Tech Stack:** WooCommerce core PHP, WC Admin Notes API, WordPress admin hooks, PHPUnit through `pnpm test:php:env`, PHPCS/PHPStan.

---

## Files

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoteController.php`
    - Runtime-gated registration for `admin_init` when the request is admin.
    - Ajax/version/provider/note-existence eligibility checks.
    - WC Admin `Note` creation from the preserved native note manifest.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoteControllerTest.php`
    - TDD coverage for registration gates, add-note blockers, and note manifest save behavior through injectable test resolvers/saver.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the admin note controller from WooCommerce bootstrap beside other native multi-currency controllers.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2t-multi-currency-admin-note`
    - Record the admin note hook parity fix.

## Task 1: Write Failing Controller Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoteControllerTest.php`

- [ ] **Step 1: Create the test class**

Use `WC_Unit_Test_Case`, clear `admin_init` actions in teardown, and create a static `MultiCurrencyRuntimeArbiter` test double whose `should_core_register()` returns true only for `MultiCurrencyRuntimeArbiter::OWNER_CORE`.

- [ ] **Step 2: Add registration tests**

Assert that plugin-owned runtime registers nothing:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, true );

$sut->register();

$this->assertFalse( has_action( 'admin_init', array( $sut, 'handle_admin_init' ) ) );
```

Assert that core-owned runtime outside admin registers nothing:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, false );

$sut->register();

$this->assertFalse( has_action( 'admin_init', array( $sut, 'handle_admin_init' ) ) );
```

Assert that core-owned admin runtime registers the preserved hook once at priority 10:

```php
$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, true );

$sut->register();
$sut->register();

$this->assertSame( 10, has_action( 'admin_init', array( $sut, 'handle_admin_init' ) ) );
```

- [ ] **Step 3: Add add-note blocker tests**

Use injectable resolvers so each blocker is isolated:

```php
$sut->set_ajax_request_resolver( static fn(): bool => true );
$sut->set_wc_version_resolver( static fn(): string => '11.0.0' );
$sut->set_provider_connected_resolver( static fn(): bool => true );
$sut->set_note_can_be_added_resolver( static fn(): bool => true );
```

Assert no note is saved for Ajax requests, unsupported WC versions, disconnected providers, and `can_be_added=false`.

- [ ] **Step 4: Add save behavior test**

Inject `provider_connected=true`, `can_be_added=true`, `is_ajax=false`, and `wc_version=11.0.0`. Inject a note saver callable that captures the manifest. Call `handle_admin_init()` and assert the saved manifest includes:

```php
array(
	'name'   => 'wc-payments-notes-multi-currency-available',
	'title'  => 'Sell worldwide in multiple currencies',
	'source' => 'woocommerce-payments',
)
```

Also assert the first action has label `Set up now`, query `admin.php?page=wc-admin&path=/payments/multi-currency-setup`, status `unactioned`, and `primary=true`.

- [ ] **Step 5: Run the focused test to verify RED**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAdminNoteControllerTest
```

Expected: fail because `MultiCurrencyAdminNoteController` does not exist yet.

## Task 2: Implement The Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoteController.php`

- [ ] **Step 1: Add controller skeleton**

Implement `RegisterHooksInterface`, inject `MultiCurrencyRuntimeArbiter` and `WooPaymentsProvider` through `init()`, and add internal setters for admin-request, Ajax, WC-version, provider-connected, note-can-be-added, and note-saver callables.

- [ ] **Step 2: Register the preserved hook**

Register only when core owns multi-currency and the request is admin:

```php
if ( ! $this->arbiter->should_core_register() || ! $this->is_admin_request() ) {
	return;
}

$this->add_action_once( 'admin_init', array( $this, 'handle_admin_init' ) );
```

- [ ] **Step 3: Implement note add handling**

Call:

```php
$manifest = MultiCurrencyAdminNoteProjectionService::get_add_note_manifest(
	$this->is_ajax_request(),
	$this->get_wc_version(),
	$this->is_provider_connected(),
	$this->can_note_be_added()
);
```

Return early when `should_add` is false. Otherwise pass `$manifest['note']` to the saver.

- [ ] **Step 4: Implement default production integration**

Default provider connection should call `WooPaymentsProvider::can_process_payments()` inside a `try/catch` and return false on errors. Default note eligibility should return false when WC Admin notes are unsupported or an existing note with the same name exists. Default note saving should create `Automattic\WooCommerce\Admin\Notes\Note`, set name/title/content/content data/type/source, add projected actions, and call `save()`.

- [ ] **Step 5: Run the focused test to verify GREEN**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAdminNoteControllerTest
```

Expected: pass.

## Task 3: Bootstrap, Changelog, And Verification

**Files:**

- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Create: `plugins/woocommerce/changelog/add-native-payments-b2t-multi-currency-admin-note`

- [ ] **Step 1: Register the controller in WooCommerce bootstrap**

Add the controller beside the other native multi-currency controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoteController::class )->register();
```

- [ ] **Step 2: Add the changelog entry**

Use this entry:

```text
Significance: patch
Type: fix
Comment: Add native multi-currency WC Admin note hook registration.
```

- [ ] **Step 3: Run focused and regression checks**

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAdminNoteControllerTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyAdminNoteController.php --memory-limit=2G
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyAdminNoteController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoteControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2t.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 4: Commit only source, test, bootstrap, and changelog files**

Do not stage `docs/superpowers/`, `.agents/`, generated assets, or the external staging log.

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoteController.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoteControllerTest.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/changelog/add-native-payments-b2t-multi-currency-admin-note
git commit -m "fix(payments): add multi-currency admin note"
```
