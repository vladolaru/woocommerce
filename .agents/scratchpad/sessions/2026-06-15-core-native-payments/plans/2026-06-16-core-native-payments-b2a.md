# Core Native Payments B2a Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a native multi-currency runtime registration manifest gated by
the multi-currency arbiter.

**Architecture:** Create one focused `MultiCurrencyRuntimeRegistry` under
`src/Internal/MultiCurrency/`. The registry consumes
`MultiCurrencyRuntimeArbiter`, reports why core multi-currency cannot register
in plugin/no-owner modes, and exposes the native hook groups that later B2
slices will turn into live registrations. This slice stays non-mutating: no
price filters, no widget/block registration, no asset migration, no WooPayments
edit, and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Runtime Registry Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [x] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$sut = new MultiCurrencyRuntimeRegistry();
$sut->init( new StaticMultiCurrencyRuntimeArbiter( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN ) );

$manifest = $sut->get_registration_manifest();

$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, $manifest['owner'] );
$this->assertFalse( $manifest['should_register'] );
$this->assertSame( array( 'plugin_multi_currency_active' ), $manifest['blockers'] );
$this->assertSame( array(), $manifest['hook_groups'] );
```

Also specify:

- no-owner mode returns `should_register => false`,
  `no_multi_currency_owner`, and no hook groups.
- core-owner mode returns `should_register => true`, no blockers, and hook
  groups for `frontend_prices`, `frontend_currencies`, `compatibility`,
  `async_prices`, `storefront`, `settings`, `rest`, `user_settings`,
  `admin_notices`, `admin_notes`, and `tracking`.
- the frontend price group includes one entry each for the preserved
  WooPayments product, variation, shipping, coupon, order-meta, Store API, and
  query-loop hooks.
- the frontend currency group includes one entry each for the preserved
  WooPayments currency/format/cart-hash/order-context hooks.
- the price hook list has no duplicate hook names, preventing a registry-level
  double-price-filter declaration before live registration exists.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyRuntimeRegistryTest
```

Expected: fail because `MultiCurrencyRuntimeRegistry` does not exist.

## Task 2: Implement Runtime Registry

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyRuntimeRegistry` with:

```php
final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void {}

public function get_registration_manifest(): array {}

public static function get_core_hook_groups(): array {}
```

Implementation requirements:

- Return a registration manifest with `owner`, `should_register`, `blockers`,
  and `hook_groups`.
- Use `MultiCurrencyRuntimeArbiter::should_core_register()` as the only signal
  for native hook eligibility.
- Return `plugin_multi_currency_active` when the owner is
  `MultiCurrencyRuntimeArbiter::OWNER_PLUGIN`.
- Return `no_multi_currency_owner` when the owner is
  `MultiCurrencyRuntimeArbiter::OWNER_NONE`.
- Return no hook groups unless core owns multi-currency.
- In core mode, collect already-projected manifests from:
  `MultiCurrencyCompatibilityProjectionService`,
  `MultiCurrencyAsyncPriceProjectionService`,
  `MultiCurrencyStorefrontProjectionService`,
  `MultiCurrencySettingsProjectionService`,
  `MultiCurrencyRestProjectionService`,
  `MultiCurrencyUserSettingsProjectionService`,
  `MultiCurrencyAdminNoticeProjectionService`, and
  `MultiCurrencyAdminNoteProjectionService`.
- Define explicit metadata-only manifests for frontend price/currency groups
  because those projection services expose behavior but not hook metadata yet.
- Do not call `add_filter()`, `add_action()`, `register_rest_route()`,
  `register_widget()`, `register_block_type()`, or asset enqueue APIs.

- [x] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2a-multi-currency-runtime-registry`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency runtime registration manifest.
```

- [x] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeRegistryTest|MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2a.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php plugins/woocommerce/changelog/add-native-payments-b2a-multi-currency-runtime-registry
git commit -m "feat(payments): add native payments B2a multi-currency runtime registry"
```

Self-review:

- B2a gates all native MC hook groups behind `MultiCurrencyRuntimeArbiter`.
- Plugin/no-owner modes expose no mutating hook groups.
- Core mode produces one metadata manifest for each planned hook group.
- No WPCOM or WooPayments client file is modified.
- No live WordPress hooks, routes, widgets, blocks, or assets are registered.
