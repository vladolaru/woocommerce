# Core Native Payments B1n Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native multi-currency async price renderer projection
that preserves WooPayments cache-mode markup, screen-reader annotations, and
asset config without registering hooks or enqueueing assets.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/`. The service exposes pure/static
projection methods for activation blockers, hook/action metadata, skeleton
markup, sale/range accessibility annotations, and asset-localization metadata.
This slice remains non-mutating: no `add_filter()`, no `add_action()`, no
`wp_enqueue_script()`, no session reads or writes, no currency switching, no
WooPayments client edit, and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Async Price Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAsyncPriceProjectionServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$manifest = MultiCurrencyAsyncPriceProjectionService::get_hook_manifest(
    true,
    false,
    false,
    false,
    false
);

$this->assertSame(
    array(
        'wc_price',
        'woocommerce_format_sale_price',
        'woocommerce_format_price_range',
    ),
    array_keys( $manifest['filters'] )
);
$this->assertSame( 999, $manifest['filters']['wc_price']['priority'] );
$this->assertSame( 5, $manifest['filters']['wc_price']['accepted_args'] );
$this->assertSame(
    array( 'wp_enqueue_scripts' ),
    array_keys( $manifest['actions'] )
);
```

Also specify:

- `get_activation_blockers()` returns stable blocker keys for inactive cache
  mode, admin context, cron context, admin REST context, and active sessions.
- `should_activate()` is true only when cache mode is active and none of those
  blockers are present.
- `wrap_price_with_skeleton()` returns the WooPayments-compatible outer span,
  `data-wcpay-price`, `data-wcpay-price-type`, `wcpay-price-skeleton`, and
  `wcpay-price-placeholder` markup, using the unformatted price argument and
  preserving sanitized placeholder HTML.
- `annotate_sale_price_screen_reader_text()` annotates the first two plain
  `screen-reader-text` spans with `sale_original` and `sale_current` data
  attributes while preserving `screen-reader-text wcpay-price-placeholder`
  spans.
- `annotate_price_range_screen_reader_text()` annotates the first range screen
  reader span with `data-wcpay-sr-price-from` and
  `data-wcpay-sr-price-to`.
- Both annotation methods return the original HTML unchanged when prices are
  non-numeric.
- `get_asset_manifest()` returns handle
  `wcpay-multi-currency-async-renderer`, script path
  `dist/multi-currency-async-renderer`, style path
  `dist/multi-currency-async-renderer.css`, localized object
  `wcpayAsyncPriceConfig`, API URL, decoded default-currency config, screen
  reader templates, session cache key `wcpay_mc_async_config`, session cache
  TTL `300000`, timeout `10000`, and max cache size `500`.

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAsyncPriceProjectionServiceTest
```

Expected: fail because `MultiCurrencyAsyncPriceProjectionService` does not
exist.

## Task 2: Implement Async Price Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAsyncPriceProjectionService.php`

- [ ] **Step 1: Write minimal implementation**

Create `MultiCurrencyAsyncPriceProjectionService` with:

```php
public static function get_activation_blockers(
    bool $cache_optimized_mode,
    bool $is_admin,
    bool $doing_cron,
    bool $is_admin_api_request,
    bool $has_active_session
): array {}

public static function should_activate(
    bool $cache_optimized_mode,
    bool $is_admin,
    bool $doing_cron,
    bool $is_admin_api_request,
    bool $has_active_session
): bool {}

public static function get_hook_manifest(
    bool $cache_optimized_mode,
    bool $is_admin,
    bool $doing_cron,
    bool $is_admin_api_request,
    bool $has_active_session
): array {}

public static function wrap_price_with_skeleton(
    string $price_html,
    $unformatted_price,
    string $price_type = 'product'
): string {}

public static function annotate_sale_price_screen_reader_text(
    string $price_html,
    $regular_price,
    $sale_price
): string {}

public static function annotate_price_range_screen_reader_text(
    string $price_html,
    $from,
    $to
): string {}

public static function get_default_currency_config(
    string $symbol,
    int $decimals,
    string $decimal_separator,
    string $thousand_separator,
    string $symbol_position
): array {}

public static function get_asset_manifest(
    string $api_url,
    array $default_currency,
    string $style_url = '',
    string $style_version = ''
): array {}
```

Implementation requirements:

- Preserve WooPayments hook names, callbacks, priorities, and accepted-arg
  counts as metadata only.
- Use `esc_attr()` for raw price/type data attributes and `wp_kses_post()` for
  the original formatted price placeholder.
- Annotate only exact `<span class="screen-reader-text">` openings so async
  placeholder spans are not annotated.
- Return original HTML when annotation inputs are non-numeric or regex
  replacement fails.
- Decode default currency symbols with `html_entity_decode()` so localized
  fallback config contains characters rather than HTML entities.
- Keep all methods static because outputs depend only on explicit inputs and
  WordPress escaping/translation helpers.

- [ ] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1n-multi-currency-async-price-projection`

- [ ] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency async price projection.
```

- [ ] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1n.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [ ] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAsyncPriceProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAsyncPriceProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1n-multi-currency-async-price-projection
git commit -m "feat(payments): add native payments B1n multi-currency async price projection"
```

Self-review:

- B1n covers async renderer activation, hook/action metadata, server markup,
  screen-reader annotations, localized asset config, and client cache constants.
- No WPCOM or WooPayments client file is modified.
- No hooks, scripts, styles, sessions, currency state, or order data are
  mutated.
