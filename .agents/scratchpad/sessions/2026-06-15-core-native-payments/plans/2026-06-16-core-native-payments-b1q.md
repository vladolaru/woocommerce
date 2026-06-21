# Core Native Payments B1q Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native My Account presentment-currency projection
that preserves WooPayments user setting hooks, field markup, options, and save
intent without registering hooks or updating selected currency state.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/`. The service returns account-detail hook
metadata, activation blockers, field option metadata, rendered field markup, and
sanitized save intent from explicit inputs. This slice remains non-mutating: no
`add_action()`, no `$_POST` reads, no selected-currency writes, no session
mutation, no WooPayments edit, and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add User Settings Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyUserSettingsProjectionServiceTest.php`

- [x] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$manifest = MultiCurrencyUserSettingsProjectionService::get_hook_manifest( 3 );

$this->assertSame( array(), $manifest['blockers'] );
$this->assertSame(
    array(
        array(
            'hook'     => 'woocommerce_edit_account_form',
            'callback' => 'add_presentment_currency_switch',
            'priority' => 10,
        ),
        array(
            'hook'     => 'woocommerce_save_account_details',
            'callback' => 'save_presentment_currency',
            'priority' => 10,
        ),
    ),
    $manifest['actions']
);
```

Also specify:

- `get_activation_blockers()` returns `single_currency` when only one currency
  is enabled and returns no blockers for multiple currencies.
- `should_activate()` is true only when multiple currencies are enabled.
- `get_currency_options()` returns WooPayments-compatible `symbol code` labels
  and selected state for each enabled currency.
- `get_presentment_currency_field_markup()` includes the preserved
  `woocommerce-form-row woocommerce-form-row--first form-row form-row-first`
  wrapper, `wcpay_selected_currency` label/select name/id, selected option, and
  "Select your preferred currency for shopping and payments." help text.
- `get_save_presentment_currency_intent()` returns a sanitized currency code
  when `wcpay_selected_currency` is present in explicit posted data.
- `get_save_presentment_currency_intent()` returns a no-op intent when the
  field is missing, empty, or non-scalar.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyUserSettingsProjectionServiceTest
```

Expected: fail because `MultiCurrencyUserSettingsProjectionService` does not
exist.

## Task 2: Implement User Settings Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyUserSettingsProjectionService.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyUserSettingsProjectionService` with:

```php
public static function get_activation_blockers(
    int $enabled_currency_count
): array {}

public static function should_activate(
    int $enabled_currency_count
): bool {}

public static function get_hook_manifest(
    int $enabled_currency_count
): array {}

public static function get_currency_options(
    array $enabled_currencies,
    string $selected_code
): array {}

public static function get_presentment_currency_field_markup(
    array $enabled_currencies,
    string $selected_code
): string {}

public static function get_save_presentment_currency_intent(
    array $posted_data
): array {}
```

Implementation requirements:

- Preserve WooPayments hook names, callback markers, default priorities, field
  name/id, wrapper classes, label text, and help text.
- Accept enabled currencies as explicit arrays with `code` and `symbol` keys
  instead of depending on a live `MultiCurrency` instance.
- Return activation diagnostics as stable blocker keys.
- Return a save intent like
  `array( 'should_update' => true, 'currency_code' => 'GBP' )` only after
  sanitizing explicit posted data with `wp_unslash()` and `wc_clean()`.
- Return metadata and markup only; do not register hooks, read `$_POST`, or
  call `update_selected_currency()`.

- [x] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1q-multi-currency-user-settings-projection`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency user settings projection.
```

- [x] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1q.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyUserSettingsProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyUserSettingsProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1q-multi-currency-user-settings-projection
git commit -m "feat(payments): add native payments B1q multi-currency user settings projection"
```

Self-review:

- B1q covers account-details hook metadata, default-currency field markup,
  currency options, selected state, and sanitized save intent.
- No WPCOM or WooPayments client file is modified.
- No hooks, sessions, request globals, options, selected currency state, or
  order data are mutated.
