# Core Native Payments B1l Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native multi-currency REST route/header projection
that preserves the WooPayments REST surface without registering routes.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/` that returns a deterministic route
manifest for the WooPayments multi-currency REST API and the public config cache
header. Keep data payload projection in the existing frontend projection
service. Keep this slice non-mutating: no `register_rest_route()`, no permission
callback execution, no settings/currency writes, no WPCOM edit, and no
WooPayments client edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add REST Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRestProjectionServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` that specifies these behaviors:

```php
$sut = new MultiCurrencyRestProjectionService();
$routes = $sut->get_route_manifest( true );

$this->assertSame( 'wc/v3', $routes['namespace'] );
$this->assertSame( 'payments/multi-currency', $routes['base'] );
$this->assertArrayHasKey( 'public_config', $routes['routes'] );
$this->assertSame(
    '/payments/multi-currency/public/config',
    $routes['routes']['public_config']['path']
);
$this->assertSame( array( 'GET' ), $routes['routes']['public_config']['methods'] );
$this->assertSame( 'public', $routes['routes']['public_config']['permission'] );
```

Also specify:

- `public_config` is omitted when cache-optimized mode is inactive.
- Admin routes require the `manage_woocommerce` permission marker.
- The single-currency GET/POST route specs preserve the
  `(?P<currency_code>[A-Za-z]{3})` path and argument schemas.
- `update-settings` preserves the `speed` and `cache` rendering mode enum.
- Public config headers include `Cache-Control: private, max-age=300`.

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRestProjectionServiceTest
```

Expected: fail because `MultiCurrencyRestProjectionService` does not exist.

## Task 2: Implement REST Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRestProjectionService.php`

- [ ] **Step 1: Write minimal implementation**

Create `MultiCurrencyRestProjectionService` with:

```php
public function get_route_manifest(
    bool $include_public_config = false
): array {}

public function get_public_config_headers(): array {}
```

Implementation requirements:

- Return namespace `wc/v3` and base `payments/multi-currency`.
- Always include admin route specs for:
  `currencies`, `update_enabled_currencies`, `single_currency_settings`,
  `single_currency_update`, `get_settings`, and `update_settings`.
- Include `public_config` only when `$include_public_config` is true.
- Express methods as `GET` and `POST` strings.
- Express permissions as `public` or `manage_woocommerce` markers.
- Preserve WooPayments argument names, types, required flags, formats, and enum.
- Return the public config `Cache-Control` header projection.

- [ ] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1l-multi-currency-rest-projection`

- [ ] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency REST route projection.
```

- [ ] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1l.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

- [ ] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyRestProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRestProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1l-multi-currency-rest-projection
git commit -m "feat(payments): add native payments B1l multi-currency REST projection"
```

Self-review:

- B1l covers route identity, method identity, permission markers, argument
  schemas, public-route conditional registration intent, and cache headers.
- No WPCOM or WooPayments client file is modified.
- No route registration, permission callback execution, or setting mutation is
  introduced.
