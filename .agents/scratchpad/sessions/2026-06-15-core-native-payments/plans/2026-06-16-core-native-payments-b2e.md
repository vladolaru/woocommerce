# Core Native Payments B2e Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add native multi-currency selected-currency write entry points so core writes the same `wcpay_currency` state B2d already reads.

**Architecture:** Add a small persistence service that validates against native `MultiCurrencyStateBuilder`, then writes `wcpay_currency` to user meta or the WooCommerce session and schedules cart recalculation. Add an arbiter-gated controller for `?currency=...`, new-customer carry-over, and My Account account-details preference, reusing `MultiCurrencyUserSettingsProjectionService` for markup and save intent.

**Tech Stack:** WooCommerce core PHP, WordPress hooks, WooCommerce sessions/cart, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## Context

WooPayments references, read-only:

- `includes/multi-currency/MultiCurrency.php:226` registers `init`, `widgets_init`, `woocommerce_created_customer`, and My Account hooks indirectly.
- `includes/multi-currency/MultiCurrency.php:775` writes selected currency to `wcpay_currency`.
- `includes/multi-currency/MultiCurrency.php:827` handles explicit `?currency=...` switching at `init` priority `11`.
- `includes/multi-currency/MultiCurrency.php:1056` copies session currency to new customer user meta.
- `includes/multi-currency/UserSettings.php:38` renders and saves My Account default currency.

B2e deliberately does not solve the broader B2 parity backlog: request-context split for price hooks, live compatibility classes, order-currency formatting callbacks, shipping `price_decimals`, async rendering, REST/settings, switcher widget/block registration, or Storefront placement.

## File Structure

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceService.php`
    - Validates selected currency codes against enabled currencies.
    - Writes `wcpay_currency` to user meta or WooCommerce session.
    - Copies guest session currency to new customer meta.
    - Recalculates cart immediately after `wp_loaded`, or schedules recalculation.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
    - Registers live hooks only when `MultiCurrencyRuntimeArbiter::should_core_register()`.
    - Handles `init` priority `11`, `woocommerce_created_customer`, `woocommerce_edit_account_form`, and `woocommerce_save_account_details`.
    - Reuses `MultiCurrencyUserSettingsProjectionService`.
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the new controller alongside the existing multi-currency controllers.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add selected-currency hook metadata for the live B2e surface.
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the manifest includes selected-currency hooks.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2e-multi-currency-selected-currency-entry-points`

## Tasks

### Task 1: Persistence Service

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceService.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySelectedCurrencyPersistenceServiceTest.php`

- [ ] **Step 1: Write failing persistence tests**

Add tests for these behaviors:

```php
public function test_updates_logged_in_user_meta_for_enabled_currency(): void
public function test_rejects_disabled_currency_without_writing_user_meta(): void
public function test_updates_guest_session_for_enabled_currency(): void
public function test_copies_guest_session_currency_to_new_customer_meta(): void
public function test_recalculates_cart_after_currency_change(): void
```

- [ ] **Step 2: Run the red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyPersistenceServiceTest
```

Expected: fail because `MultiCurrencySelectedCurrencyPersistenceService` does not exist.

- [ ] **Step 3: Implement the persistence service**

Implement only the tested API:

```php
public function update_selected_currency( string $currency_code, bool $persist_change = true ): bool
public function set_new_customer_currency_meta( int $customer_id ): bool
public function has_additional_currencies_enabled(): bool
public function get_enabled_currency_options(): array
public function get_selected_currency_code(): string
public function recalculate_cart(): void
```

Use `MultiCurrencyStateBuilder::build()` for validation. Preserve key string `wcpay_currency`.

- [ ] **Step 4: Run the green persistence tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyPersistenceServiceTest
```

Expected: pass.

### Task 2: Selected-Currency Controller

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySelectedCurrencyControllerTest.php`

- [ ] **Step 1: Write failing controller tests**

Add tests for these behaviors:

```php
public function test_does_not_register_selected_currency_hooks_when_plugin_owns_runtime(): void
public function test_registers_selected_currency_hooks_when_core_owns_runtime(): void
public function test_updates_currency_from_url_parameter(): void
public function test_renders_account_currency_field_when_multiple_currencies_are_enabled(): void
public function test_saves_account_currency_field_from_posted_data(): void
```

- [ ] **Step 2: Run the red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyControllerTest
```

Expected: fail because `MultiCurrencySelectedCurrencyController` does not exist.

- [ ] **Step 3: Implement the controller**

Register hook identities:

```php
add_action( 'init', array( $this, 'handle_init' ), 11 );
add_action( 'woocommerce_created_customer', array( $this, 'handle_woocommerce_created_customer' ) );
add_action( 'woocommerce_edit_account_form', array( $this, 'handle_woocommerce_edit_account_form' ) );
add_action( 'woocommerce_save_account_details', array( $this, 'handle_woocommerce_save_account_details' ) );
```

Keep callbacks safe if only one currency is enabled.

- [ ] **Step 4: Run the green controller tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySelectedCurrencyControllerTest
```

Expected: pass.

### Task 3: Boot Registration and Manifest

**Files:**

- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Write failing registry/boot assertions**

Extend registry coverage to expect a `selected_currency` hook group with:

```text
init: update_selected_currency_by_url, priority 11
woocommerce_created_customer: set_new_customer_currency_meta
woocommerce_edit_account_form: add_presentment_currency_switch
woocommerce_save_account_details: save_presentment_currency
```

- [ ] **Step 2: Run the red registry test**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRuntimeRegistryTest
```

Expected: fail because `selected_currency` is not in the manifest.

- [ ] **Step 3: Wire the controller and registry manifest**

Add the controller to `class-woocommerce.php` after the existing multi-currency frontend controllers, and add static manifest metadata to `MultiCurrencyRuntimeRegistry`.

- [ ] **Step 4: Run focused B2e tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyStateBuilderTest|MultiCurrencyUserSettingsProjectionServiceTest'
```

Expected: pass.

### Task 4: Verification, Changelog, Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2e-multi-currency-selected-currency-entry-points`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog entry**

Use a patch-style changelog file consistent with prior B2 slices.

- [ ] **Step 2: Run regression and static checks**

Run focused regression:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySelectedCurrencyPersistenceServiceTest|MultiCurrencySelectedCurrencyControllerTest|MultiCurrencyFrontendPricesControllerTest|MultiCurrencyFrontendCurrenciesControllerTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run static checks:

```bash
cd plugins/woocommerce
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
cd ../..
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
npx markdownlint-cli2 .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2e.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
```

- [ ] **Step 3: Stage only B2e code/changelog**

Do not stage `.agents/`, `docs/superpowers/`, or the external staging log.

- [ ] **Step 4: Run staged lint and commit**

Run:

```bash
git diff --cached --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
git commit
```

Commit subject:

```text
feat(payments): add native payments B2e multi-currency selected currency entry points
```

## Self-Review

- Spec coverage: B2e covers selected-currency write entry points and My Account preference only, matching the sidecar recommendation and preserving `wcpay_currency`.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: service/controller names and method signatures are consistent across tasks.
