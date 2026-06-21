# Core Native Payments B1p Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native Storefront multi-currency switcher projection
that preserves WooPayments Storefront theme activation, breadcrumb placement,
inline CSS, and hook metadata without registering hooks or styles.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/`. The service returns Storefront theme
compatibility, activation blockers, hook/action metadata, default widget args,
inline-style metadata, and breadcrumb-default projections from explicit inputs.
This slice remains non-mutating: no theme-global reads, no `add_action()`, no
`add_filter()`, no `wp_add_inline_style()`, no widget instantiation, no
WooPayments edit, and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Storefront Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStorefrontProjectionServiceTest.php`

- [x] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$manifest = MultiCurrencyStorefrontProjectionService::get_hook_manifest(
    2,
    true,
    array()
);

$this->assertSame( array(), $manifest['blockers'] );
$this->assertSame(
    array(
        array(
            'hook'     => 'woocommerce_breadcrumb_defaults',
            'callback' => 'inject_switcher_into_breadcrumb',
            'priority' => 9999,
        ),
    ),
    $manifest['filters']
);
```

Also specify:

- `is_storefront_theme()` is true when either stylesheet or template is
  `storefront`, and false otherwise.
- Activation blockers include `single_currency`, `storefront_switcher_disabled`,
  and `simulation_hides_switcher`.
- Simulation with `enable_storefront_switcher => true` activates even when the
  saved Storefront switcher setting is false.
- `get_default_widget_args()` returns the preserved wrapper with
  `woocommerce-payments-multi-currency-storefront-widget` and
  `woocommerce-breadcrumb`.
- `get_inline_style_manifest()` returns handle `storefront-style`, filter hook
  `wcpay_multi_currency_storefront_widget_css`, and CSS for the widget float
  and form margin rules.
- `get_widget_filter_manifest()` returns the preserved
  `wcpay_multi_currency_storefront_widget_instance` and
  `wcpay_multi_currency_storefront_widget_args` filter names with defaults.
- `inject_switcher_into_breadcrumb()` inserts provided widget markup before the
  first `<nav` in `wrap_before` and leaves defaults unchanged when no `<nav`
  exists.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyStorefrontProjectionServiceTest
```

Expected: fail because `MultiCurrencyStorefrontProjectionService` does not
exist.

## Task 2: Implement Storefront Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStorefrontProjectionService.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyStorefrontProjectionService` with:

```php
public static function is_storefront_theme(
    string $stylesheet,
    string $template
): bool {}

public static function get_activation_blockers(
    int $enabled_currency_count,
    bool $storefront_switcher_enabled,
    array $simulation_variables = array()
): array {}

public static function should_activate(
    int $enabled_currency_count,
    bool $storefront_switcher_enabled,
    array $simulation_variables = array()
): bool {}

public static function get_hook_manifest(
    int $enabled_currency_count,
    bool $storefront_switcher_enabled,
    array $simulation_variables = array()
): array {}

public static function get_default_widget_args(): array {}

public static function get_default_inline_css(): string {}

public static function get_inline_style_manifest(
    string $css = ''
): array {}

public static function get_widget_filter_manifest(): array {}

public static function inject_switcher_into_breadcrumb(
    array $defaults,
    string $widget_markup
): array {}
```

Implementation requirements:

- Preserve WooPayments hook names, callback markers, priorities, style handle,
  filter names, widget wrapper IDs/classes, and CSS rules.
- Accept theme and simulation state as explicit inputs instead of reading
  globals or request data.
- Return activation diagnostics as stable blocker keys.
- Return metadata and transformed arrays only; do not register hooks or add
  inline styles.

- [x] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1p-multi-currency-storefront-projection`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency Storefront projection.
```

- [x] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1p.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyStorefrontProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyStorefrontProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1p-multi-currency-storefront-projection
git commit -m "feat(payments): add native payments B1p multi-currency Storefront projection"
```

Self-review:

- B1p covers Storefront activation, hook/action metadata, CSS/style metadata,
  widget wrapper args, widget filter names, and breadcrumb insertion.
- No WPCOM or WooPayments client file is modified.
- No hooks, styles, widgets, theme globals, sessions, options, or order data are
  mutated.
