# Core Native Payments B1o Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native multi-currency settings projection that
preserves the WooPayments settings tab, onboarding CTA, admin asset metadata,
and mounted React container without registering settings pages or hooks.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/`. The service returns metadata and markup
for settings-page selection, field manifests, onboarding CTA state, asset
registration, and config flags. This slice remains non-mutating: no
`WC_Settings_Page` instantiation, no `add_action()`, no `wp_enqueue_script()`,
no global `$hide_save_button` mutation, no option updates, no WooPayments edit,
and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Settings Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$manifest = MultiCurrencySettingsProjectionService::get_settings_page_manifest(
    true,
    false,
    false,
    false
);

$this->assertSame( 'wcpay_multi_currency', $manifest['id'] );
$this->assertSame( 'Multi-currency', $manifest['label'] );
$this->assertSame( 'settings', $manifest['mode'] );
$this->assertTrue( $manifest['hide_save_button'] );
$this->assertSame(
    array( array( 'type' => 'wcpay_multi_currency_settings_page' ) ),
    $manifest['settings']
);
```

Also specify:

- The manifest is empty for WP-CLI, WPCOM jobs, or an upgrader completion
  request.
- The manifest switches to `onboarding_cta` mode when the provider is not
  connected.
- `get_hook_manifest()` returns metadata for `admin_print_scripts`,
  `woocommerce_admin_field_wcpay_multi_currency_settings_page`, and
  `woocommerce_admin_field_wcpay_currencies_settings_onboarding_cta`.
- `get_settings_container_markup()` returns the preserved
  `wcpay_multi_currency_settings_container` div with
  `wc-settings-prevent-change-event` and `aria-describedby`.
- `get_onboarding_settings()` returns the `Enabled currencies` title section,
  learn-more description, CTA field type, and `sectionend` row with the
  preserved setting ID.
- `get_onboarding_cta_markup()` escapes the onboarding URL, uses the preserved
  CTA ID `wcpay_enabled_currencies_onboarding_cta`, and preserves the message
  and button text.
- `is_multi_currency_settings_page()` is true only for admin requests with
  current tab `wcpay_multi_currency` and screen base
  `woocommerce_page_wc-settings`.
- `should_enqueue_admin_assets()` is true only for current tab
  `wcpay_multi_currency`.
- `get_admin_asset_manifest()` preserves handle
  `WCPAY_MULTI_CURRENCY_SETTINGS`, script path `dist/multi-currency`, script
  dependencies `WCPAY_ADMIN_SETTINGS` and `wp-components`, style path
  `dist/multi-currency.css`, and style dependencies `wc-components` and
  `WCPAY_ADMIN_SETTINGS`.
- `add_props_to_wcpay_js_config()` returns a copy of the config with
  `isMultiCurrencyEnabled` set to true.

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySettingsProjectionServiceTest
```

Expected: fail because `MultiCurrencySettingsProjectionService` does not
exist.

## Task 2: Implement Settings Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionService.php`

- [ ] **Step 1: Write minimal implementation**

Create `MultiCurrencySettingsProjectionService` with:

```php
public static function get_settings_page_manifest(
    bool $provider_connected,
    bool $is_cli,
    bool $is_wpcom_jobs,
    bool $did_upgrade
): array {}

public static function get_hook_manifest(): array {}

public static function get_settings_container_markup(): string {}

public static function get_onboarding_settings(): array {}

public static function get_onboarding_cta_markup( string $onboarding_url ): string {}

public static function is_multi_currency_settings_page(
    bool $is_admin,
    ?string $current_tab,
    ?string $screen_base
): bool {}

public static function should_enqueue_admin_assets( ?string $current_tab ): bool {}

public static function get_admin_asset_manifest(
    string $style_url = '',
    string $style_version = ''
): array {}

public static function add_props_to_wcpay_js_config( array $config ): array {}
```

Implementation requirements:

- Preserve IDs, field types, asset handles, paths, and dependencies.
- Use WooCommerce text domain for core-native translated strings.
- Return manifest data instead of mutating globals or registering hooks.
- Escape onboarding CTA URL and output with WooCommerce/WordPress escaping
  helpers.
- Keep methods static because outputs depend only on explicit inputs and
  escaping/translation helpers.

- [ ] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1o-multi-currency-settings-projection`

- [ ] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency settings projection.
```

- [ ] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1o.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [ ] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1o-multi-currency-settings-projection
git commit -m "feat(payments): add native payments B1o multi-currency settings projection"
```

Self-review:

- B1o covers settings tab metadata, container markup, onboarding CTA, asset
  manifest, settings-page detection, and JS config projection.
- No WPCOM or WooPayments client file is modified.
- No settings pages, hooks, assets, globals, options, sessions, or order data
  are mutated.
