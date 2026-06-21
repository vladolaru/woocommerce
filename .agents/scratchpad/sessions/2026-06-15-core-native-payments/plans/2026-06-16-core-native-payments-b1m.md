# Core Native Payments B1m Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native multi-currency compatibility projection that
preserves the WooPayments compatibility inventory and switching-disable
decision inputs without registering hooks.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/` that exposes the compatibility
integration manifest, hook/filter manifest, and pure switching-disable reason
calculation. Keep this slice non-mutating: no `add_action()`, no `add_filter()`,
no compatibility class instantiation, no cart/order inspection, no order writes,
no WPCOM edit, and no WooPayments client edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Compatibility Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyCompatibilityProjectionServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` that specifies these behaviors:

```php
$sut = new MultiCurrencyCompatibilityProjectionService();

$this->assertSame(
    array(
        'WooCommerceBookings',
        'WooCommerceFedEx',
        'WooCommerceNameYourPrice',
        'WooCommercePreOrders',
        'WooCommerceProductAddOns',
        'WooCommerceSubscriptions',
        'WooCommerceUPS',
        'WooCommerceDeposits',
        'WooCommercePointsAndRewards',
    ),
    $sut->get_compatibility_integrations( true )
);
```

Also specify:

- Integrations are empty when only one currency is enabled.
- The hook manifest includes the compatibility init action, cron sales-record
  filter, and preserved filter names under the `wcpay_multi_currency_` prefix.
- `get_switching_disable_reasons()` returns `pay_for_order` when the query
  args contain `pay_for_order`.
- The same method returns `subscription_context` and `external_filter` for
  supplied subscription/filter inputs.
- `should_disable_currency_switching()` is true when any reason is present and
  false when none are present.

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyCompatibilityProjectionServiceTest
```

Expected: fail because `MultiCurrencyCompatibilityProjectionService` does not
exist.

## Task 2: Implement Compatibility Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyCompatibilityProjectionService.php`

- [ ] **Step 1: Write minimal implementation**

Create `MultiCurrencyCompatibilityProjectionService` with:

```php
public function get_compatibility_integrations(
    bool $has_additional_currencies
): array {}

public function get_hook_manifest(): array {}

public function get_switching_disable_reasons(
    array $query_args = array(),
    bool $subscription_context = false,
    bool $external_filter_disabled = false
): array {}

public function should_disable_currency_switching(
    array $query_args = array(),
    bool $subscription_context = false,
    bool $external_filter_disabled = false
): bool {}
```

Implementation requirements:

- Preserve the WooPayments compatibility integration order.
- Preserve hook/filter names without registering them.
- Accept query args as an argument rather than reading `$_GET`.
- Accept subscription/filter state as explicit booleans rather than inspecting
  carts, superglobals, or callbacks.
- Return reason keys as stable strings for later diagnostics.

- [ ] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1m-multi-currency-compatibility-projection`

- [ ] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency compatibility projection.
```

- [ ] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1m.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

- [ ] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyCompatibilityProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyCompatibilityProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1m-multi-currency-compatibility-projection
git commit -m "feat(payments): add native payments B1m multi-currency compatibility projection"
```

Self-review:

- B1m covers compatibility inventory, hook/filter manifest, and pure
  switching-disable reasons.
- No WPCOM or WooPayments client file is modified.
- No hooks, compatibility classes, cart/order reads, or mutations are
  introduced.
